<?php
/**
 * WealthDash — Tax Planning Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser   = require_auth();
$pageTitle     = 'Tax Planning';
$activePage    = 'report_tax';
$activeSection = 'reports';

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Tax Planning</h1>
        <p class="page-subtitle">LTCG Harvest Suggestions · FD TDS · 80TTA Benefits</p>
    </div>
    <div class="page-actions">
        <select id="taxFyFilter" class="form-select" style="width:auto">
            <option value="">Current FY</option>
        </select>
        <button class="btn btn-primary" id="loadTaxPlanBtn">Refresh</button>
    </div>
</div>

<!-- Summary Cards -->
<div class="cards-grid cards-grid-4 mb-4" id="taxSummaryCards"></div>

<!-- LTCG Exemption Progress -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title">LTCG ₹1,25,000 Exemption Tracker</h3>
        <span class="badge badge-info" id="taxFyBadge"></span>
    </div>
    <div class="card-body">
        <div class="flex-between mb-2">
            <span class="text-sm">Realised LTCG: <strong id="ltcgRealised" class="text-danger">₹0</strong></span>
            <span class="text-sm">Exemption Used: <strong id="ltcgExUsed">₹0</strong></span>
            <span class="text-sm">Remaining: <strong id="ltcgExRemaining" class="text-success">₹1,25,000</strong></span>
        </div>
        <div class="progress-bar-wrap mb-3">
            <div class="progress-bar progress-success" id="taxProgressBar" style="width:0%"></div>
        </div>
        <p class="text-sm text-secondary" id="ltcgHarvestMsg"></p>
    </div>
</div>

<!-- Harvest Suggestions -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title">🌾 Tax Harvest Suggestions</h3>
        <small class="text-secondary">Holdings where you can book LTCG within ₹1.25L exemption</small>
    </div>
    <div class="table-wrap">
        <table class="data-table" id="harvestTable">
            <thead>
                <tr>
                    <th>Type</th><th>Name</th><th>Category</th>
                    <th class="text-right">Total Units/Qty</th>
                    <th class="text-right">Current NAV/Price</th>
                    <th class="text-right">Total LTCG Gain (₹)</th>
                    <th>LTCG Since</th>
                    <th class="text-right">Suggested Sell Units</th>
                    <th class="text-right">Gain if Sold (₹)</th>
                    <th class="text-right">Value if Sold (₹)</th>
                    <th class="text-right">CAGR %</th>
                </tr>
            </thead>
            <tbody id="harvestBody"><tr><td colspan="11" class="text-center text-secondary">Loading...</td></tr></tbody>
        </table>
    </div>
</div>

<!-- Wait for LTCG -->
<div class="card mb-4" id="waitLtcgCard">
    <div class="card-header">
        <h3 class="card-title">⏳ Wait Before Selling — STCG to LTCG</h3>
        <small class="text-secondary">Holdings converting to LTCG within 90 days</small>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Type</th><th>Name</th><th>Category</th><th class="text-right">Unrealised Gain (₹)</th><th>LTCG Date</th><th>Days Remaining</th><th>Advice</th></tr></thead>
            <tbody id="waitLtcgBody"><tr><td colspan="7" class="text-center text-secondary">No upcoming LTCG conversions</td></tr></tbody>
        </table>
    </div>
</div>

<!-- FD & Savings Tax -->
<div class="cards-grid cards-grid-3 mb-4">
    <div class="card">
        <div class="card-header"><h3 class="card-title">🏦 FD Interest & TDS</h3></div>
        <div class="card-body">
            <div class="stat-row"><span>Total FD Interest (FY)</span><strong id="fdInterest">₹0</strong></div>
            <div class="stat-row"><span>TDS Deducted (FY)</span><strong id="fdTds" class="text-warning">₹0</strong></div>
            <p class="text-sm text-secondary mt-2">FD interest taxable as per your income tax slab. TDS deducted @ 10% if &gt;₹40,000 p.a.</p>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="card-title">💰 Savings Interest & 80TTA</h3></div>
        <div class="card-body">
            <div class="stat-row"><span>Total Savings Interest (FY)</span><strong id="savingsInterest">₹0</strong></div>
            <div class="stat-row"><span>80TTA Deduction Available</span><strong id="tta80Benefit" class="text-success">₹0</strong></div>
            <p class="text-sm text-secondary mt-2">Section 80TTA allows deduction up to ₹10,000 on savings bank interest.</p>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="card-title">📊 Tax Rates Reference</h3></div>
        <div class="card-body">
            <div class="stat-row"><span>LTCG Equity (&gt;₹1.25L)</span><strong>12.5%</strong></div>
            <div class="stat-row"><span>STCG Equity</span><strong>20%</strong></div>
            <div class="stat-row"><span>LTCG Debt (pre Apr'23)</span><strong>20% + indexation</strong></div>
            <div class="stat-row"><span>Debt (post Apr'23)</span><strong>As per slab</strong></div>
            <div class="stat-row"><span>FD Interest</span><strong>As per slab</strong></div>
        </div>
    </div>
</div>

<script src="<?= APP_URL ?>/public/js/reports.js?v=3"></script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
