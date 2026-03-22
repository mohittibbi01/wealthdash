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

<!-- 80C Dashboard (t82) -->
<div class="card mb-4" id="card80C">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title">📋 80C / 80CCD Dashboard — ₹1,50,000 + ₹50,000</h3>
        <span id="deadlineCountdown" style="font-size:12px;font-weight:700;padding:4px 12px;border-radius:99px;background:rgba(220,38,38,.1);color:#dc2626;"></span>
    </div>
    <div class="card-body">
        <!-- 80C Main limit -->
        <div style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <span style="font-size:13px;font-weight:700;">Section 80C Investments</span>
                <span style="font-size:13px;"><strong id="amt80C">₹0</strong> / ₹1,50,000</span>
            </div>
            <div style="height:10px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;margin-bottom:6px;">
                <div id="bar80C" style="height:100%;width:0%;background:#3b82f6;border-radius:99px;transition:width .6s;"></div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-top:8px;">
                <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 12px;">
                    <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">ELSS (Tax Saver MF)</div>
                    <div style="font-size:14px;font-weight:800;" id="amt80cElss">₹0</div>
                </div>
                <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 12px;">
                    <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">PPF</div>
                    <div style="font-size:14px;font-weight:800;" id="amt80cPpf">₹0</div>
                </div>
                <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 12px;">
                    <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">NPS (80C portion)</div>
                    <div style="font-size:14px;font-weight:800;" id="amt80cNps">₹0</div>
                </div>
                <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 12px;border:1.5px solid var(--border);">
                    <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Remaining 80C</div>
                    <div style="font-size:14px;font-weight:800;color:#16a34a;" id="rem80C">₹1,50,000</div>
                </div>
            </div>
        </div>
        <!-- 80CCD(1B) NPS extra -->
        <div style="border-top:1px solid var(--border);padding-top:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <span style="font-size:13px;font-weight:700;">Section 80CCD(1B) — NPS Extra Deduction</span>
                <span style="font-size:13px;"><strong id="amt80ccd1b">₹0</strong> / ₹50,000</span>
            </div>
            <div style="height:8px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
                <div id="bar80ccd1b" style="height:100%;width:0%;background:#8b5cf6;border-radius:99px;transition:width .6s;"></div>
            </div>
            <div id="msg80ccd1b" style="font-size:12px;color:var(--text-muted);margin-top:6px;"></div>
        </div>
        <div id="msg80C" style="font-size:12px;margin-top:10px;padding:8px 12px;border-radius:6px;"></div>
    </div>
</div>

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

<!-- t47: Gratuity Calculator -->
<div class="card mb-4" style="margin-top:20px;">
    <div class="card-header">
        <h3 class="card-title">🏅 Gratuity Calculator</h3>
        <span class="badge badge-info">Section 10(10) — Up to ₹20L Tax Free</span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
            <div>
                <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Monthly Basic + DA (₹)</div>
                <input type="number" id="gratSalary" placeholder="50000" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcGratuity()">
            </div>
            <div>
                <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Years of Service</div>
                <input type="number" id="gratYears" placeholder="10" min="5" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcGratuity()">
            </div>
            <div>
                <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Joining Date (optional)</div>
                <input type="date" id="gratJoining" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" onchange="calcGratuityFromDate()">
            </div>
        </div>
        <div id="gratResult" style="display:none;background:var(--bg-secondary);border-radius:10px;padding:14px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;text-align:center;">
                <div><div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Gratuity Amount</div><div style="font-size:20px;font-weight:800;color:var(--accent);" id="gratAmt">₹0</div></div>
                <div><div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Tax Exempt (₹20L limit)</div><div style="font-size:20px;font-weight:800;color:#16a34a;" id="gratExempt">₹0</div></div>
                <div><div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Taxable Amount</div><div style="font-size:20px;font-weight:800;" id="gratTaxable" style="color:var(--text-muted);">₹0</div></div>
            </div>
            <div id="gratNote" style="font-size:12px;color:var(--text-muted);margin-top:10px;padding:8px;background:rgba(59,130,246,.07);border-radius:6px;"></div>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">
            Formula: <strong>Last Salary (Basic+DA) × 15/26 × Years of Service</strong>. Minimum 5 years service required. Tax free up to ₹20L (Section 10(10)).
        </div>
    </div>
</div>

<!-- t134: 54EC Bond Tracker -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title">🏗️ 54EC Bond Tracker — Property LTCG Reinvestment</h3>
        <span class="badge badge-warning">6-Month Window</span>
    </div>
    <div class="card-body">
        <div style="background:rgba(245,158,11,.07);border:1px solid #fcd34d;border-radius:8px;padding:12px;margin-bottom:14px;font-size:12px;">
            💡 <strong>Section 54EC:</strong> Invest capital gains from property sale in NHAI/REC bonds within <strong>6 months</strong> to save LTCG tax. 
            Bond limit: ₹50L per FY. Lock-in: 5 years. Interest rate: ~5.25% p.a. (taxable).
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:14px;">
            <div>
                <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Property Sale Date</div>
                <input type="date" id="ec54SaleDate" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" onchange="calc54EC()">
            </div>
            <div>
                <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">LTCG Amount (₹)</div>
                <input type="number" id="ec54Ltcg" placeholder="2000000" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calc54EC()">
            </div>
            <div>
                <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Bonds Already Invested (₹)</div>
                <input type="number" id="ec54Invested" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calc54EC()">
            </div>
        </div>
        <div id="ec54Result" style="display:none;"></div>
    </div>
</div>

<script>
// t85: New vs Old Tax Regime Calculator
function calcTaxRegime() {
    const salary = parseFloat(document.getElementById('trcSalary')?.value) || 0;
    const hra    = parseFloat(document.getElementById('trcHra')?.value)    || 0;
    const c80    = Math.min(150000, parseFloat(document.getElementById('trc80c')?.value) || 0);
    const other  = parseFloat(document.getElementById('trcOther')?.value)  || 0;
    const res    = document.getElementById('trcResult');
    if (!res || !salary) { if(res) res.style.display='none'; return; }
    function slab(inc, r) {
        if (r==='new') {
            if(inc<=300000)return 0; if(inc<=600000)return(inc-300000)*.05;
            if(inc<=900000)return 15000+(inc-600000)*.10; if(inc<=1200000)return 45000+(inc-900000)*.15;
            if(inc<=1500000)return 90000+(inc-1200000)*.20; return 150000+(inc-1500000)*.30;
        }
        if(inc<=250000)return 0; if(inc<=500000)return(inc-250000)*.05;
        if(inc<=1000000)return 12500+(inc-500000)*.20; return 112500+(inc-1000000)*.30;
    }
    const newTaxable=Math.max(0,salary-75000), newTax=slab(newTaxable,'new')*1.04;
    const oldTaxable=Math.max(0,salary-50000-hra-c80-other), oldTax=slab(oldTaxable,'old')*1.04;
    const better=newTax<=oldTax?'new':'old', savings=Math.abs(newTax-oldTax);
    function fmtI(v){v=Math.abs(v);if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}
    res.style.display='';
    res.innerHTML=`<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
      <div style="background:${better==='new'?'rgba(22,163,74,.08)':'var(--bg-secondary)'};border-radius:10px;padding:14px;border:${better==='new'?'2px solid #86efac':'1.5px solid var(--border)'};">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">New Regime</div>
        <div style="font-size:11px;color:var(--text-muted);">Taxable: ${fmtI(newTaxable)}</div>
        <div style="font-size:20px;font-weight:800;margin-top:4px;">Tax: ${fmtI(newTax)}</div>
        ${better==='new'?'<div style="font-size:11px;color:#16a34a;font-weight:700;margin-top:4px;">✅ Better for you</div>':''}
      </div>
      <div style="background:${better==='old'?'rgba(22,163,74,.08)':'var(--bg-secondary)'};border-radius:10px;padding:14px;border:${better==='old'?'2px solid #86efac':'1.5px solid var(--border)'};">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Old Regime</div>
        <div style="font-size:11px;color:var(--text-muted);">Taxable: ${fmtI(oldTaxable)}</div>
        <div style="font-size:20px;font-weight:800;margin-top:4px;">Tax: ${fmtI(oldTax)}</div>
        ${better==='old'?'<div style="font-size:11px;color:#16a34a;font-weight:700;margin-top:4px;">✅ Better for you</div>':''}
      </div>
    </div>
    <div style="background:rgba(99,102,241,.06);border-radius:8px;padding:10px;font-size:12px;color:var(--text-muted);">
      💰 <strong>${better==='new'?'New':'Old'} Regime saves ${fmtI(savings)}/year.</strong>
      ${better==='old'?'Your deductions are high — old regime is better.':'New regime works better. Consider reducing deductions complexity.'}
    </div>`;
}

// t47: Gratuity Calculator
function calcGratuity() {
    const salary = parseFloat(document.getElementById('gratSalary')?.value) || 0;
    const years  = parseFloat(document.getElementById('gratYears')?.value)  || 0;
    const res    = document.getElementById('gratResult');
    if (!salary || years < 5 || !res) { if (res) res.style.display='none'; return; }

    // Formula: (Basic + DA) × 15/26 × Years (round down partial year after 6 months)
    const gratuity = Math.round(salary * 15 / 26 * Math.floor(years));
    const exempt   = Math.min(gratuity, 2000000); // ₹20L limit
    const taxable  = Math.max(0, gratuity - exempt);

    function fmtInr(v) {
        v = Math.abs(v);
        if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + ' Cr';
        if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
        return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
    }

    document.getElementById('gratAmt').textContent     = fmtInr(gratuity);
    document.getElementById('gratExempt').textContent  = fmtInr(exempt);
    document.getElementById('gratTaxable').textContent = fmtInr(taxable);
    document.getElementById('gratTaxable').style.color = taxable > 0 ? '#dc2626' : '#16a34a';
    const note = document.getElementById('gratNote');
    if (note) {
        note.textContent = taxable > 0
            ? `⚠️ ${fmtInr(taxable)} exceeds ₹20L exemption limit and will be taxable as per slab.`
            : `✅ Entire gratuity (${fmtInr(gratuity)}) is within ₹20L exemption limit — fully tax free!`;
    }
    res.style.display = '';
}
function calcGratuityFromDate() {
    const joining = document.getElementById('gratJoining')?.value;
    if (!joining) return;
    const years = (Date.now() - new Date(joining)) / (365.25 * 86400000);
    const el = document.getElementById('gratYears');
    if (el) { el.value = years.toFixed(1); calcGratuity(); }
}

// t134: 54EC Bond Tracker
function calc54EC() {
    const saleDate = document.getElementById('ec54SaleDate')?.value;
    const ltcg     = parseFloat(document.getElementById('ec54Ltcg')?.value) || 0;
    const invested = parseFloat(document.getElementById('ec54Invested')?.value) || 0;
    const res      = document.getElementById('ec54Result');
    if (!saleDate || !ltcg || !res) { if(res) res.style.display='none'; return; }

    const saleTs    = new Date(saleDate);
    const deadline  = new Date(saleTs); deadline.setMonth(deadline.getMonth() + 6);
    const today     = new Date();
    const daysLeft  = Math.ceil((deadline - today) / 86400000);
    const remaining = Math.max(0, Math.min(5000000, ltcg) - invested); // max ₹50L
    const taxSaved  = Math.round(invested * 0.20); // 20% LTCG tax rate for property

    function fmtI(v) {
        v = Math.abs(v);
        if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + ' Cr';
        return '₹' + (v/1e5).toFixed(1) + 'L';
    }

    const urgency = daysLeft <= 0 ? '🚨 DEADLINE PASSED'
                  : daysLeft <= 14 ? '🚨 URGENT'
                  : daysLeft <= 30 ? '⚠️ Act soon'
                  : daysLeft <= 60 ? '⚠️ Approaching'
                  : '📅 On track';
    const urgColor = daysLeft <= 30 ? '#dc2626' : daysLeft <= 60 ? '#d97706' : '#16a34a';

    res.style.display = '';
    res.innerHTML = `
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:12px;">
            <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Deadline</div>
                <div style="font-size:14px;font-weight:800;color:${urgColor};">${deadline.toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'})}</div>
                <div style="font-size:11px;color:${urgColor};font-weight:700;">${urgency} (${daysLeft > 0 ? daysLeft + ' days' : 'Expired'})</div>
            </div>
            <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Still to Invest</div>
                <div style="font-size:16px;font-weight:800;color:${remaining>0?'#d97706':'#16a34a'};">${fmtI(remaining)}</div>
                <div style="font-size:11px;color:var(--text-muted);">of ₹50L limit</div>
            </div>
            <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Tax Saved So Far</div>
                <div style="font-size:16px;font-weight:800;color:#16a34a;">${fmtI(taxSaved)}</div>
                <div style="font-size:11px;color:var(--text-muted);">@20% LTCG rate</div>
            </div>
        </div>
        ${daysLeft <= 0 ? '<div style="padding:10px 14px;background:rgba(220,38,38,.1);border-radius:7px;color:#dc2626;font-size:12px;font-weight:600;">⚠️ 6-month window has expired. You cannot invest in 54EC bonds now.</div>' : ''}
        <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">
            Eligible bonds: <strong>NHAI</strong> (National Highways Authority) and <strong>REC</strong> (Rural Electrification Corporation). Lock-in: 5 years. Interest: ~5.25% p.a. (taxable).
        </div>`;
}
</script>
            Eligible bonds: <strong>NHAI</strong> (National Highways Authority) and <strong>REC</strong> (Rural Electrification Corporation). Lock-in: 5 years. Interest: ~5.25% p.a. (taxable).
        </div>`;
}
</script>

<!-- t85: New vs Old Tax Regime Calculator -->
<div class="card mb-4" style="margin-top:20px;">
    <div class="card-header">
        <h3 class="card-title">⚖️ New vs Old Tax Regime — Which is Better?</h3>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px;">
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Gross Salary (₹)</div>
                <input type="number" id="trcSalary" placeholder="1200000" class="adv-input" oninput="calcTaxRegime()"></div>
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">HRA Exemption (₹)</div>
                <input type="number" id="trcHra" placeholder="0" class="adv-input" oninput="calcTaxRegime()"></div>
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">80C (max ₹1.5L)</div>
                <input type="number" id="trc80c" placeholder="150000" class="adv-input" oninput="calcTaxRegime()"></div>
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Other Deductions (₹)</div>
                <input type="number" id="trcOther" placeholder="0" class="adv-input" oninput="calcTaxRegime()"></div>
        </div>
        <div id="trcResult" style="display:none;"></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">
            New regime: ₹75K standard deduction, no other deductions allowed.
            Old regime: ₹50K std. ded. + HRA + 80C + 80D + all deductions.
        </div>
    </div>
</div>

<!-- t137: Advance Tax Calculator -->
<div class="card mb-4" style="margin-top:20px;">
    <div class="card-header">
        <h3 class="card-title">📅 Advance Tax Calculator</h3>
        <span class="badge badge-warning" id="advTaxUrgencyBadge"></span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:16px;">
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Annual Salary (₹)</div>
                <input type="number" id="advSalary" placeholder="1200000" class="adv-input" oninput="calcAdvTax()"></div>
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Capital Gains (₹)</div>
                <input type="number" id="advCapGains" placeholder="0" class="adv-input" oninput="calcAdvTax()"></div>
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Interest Income (₹)</div>
                <input type="number" id="advInterest" placeholder="0" class="adv-input" oninput="calcAdvTax()"></div>
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">TDS Already Deducted (₹)</div>
                <input type="number" id="advTdsPaid" placeholder="0" class="adv-input" oninput="calcAdvTax()"></div>
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Tax Regime</div>
                <select id="advRegime" class="adv-input" onchange="calcAdvTax()">
                    <option value="new">New Regime (default)</option>
                    <option value="old">Old Regime</option>
                </select></div>
        </div>
        <div id="advTaxResult" style="display:none;"></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">
            Quarterly deadlines: <strong>Jun 15 (15%)</strong> · <strong>Sep 15 (45%)</strong> · <strong>Dec 15 (75%)</strong> · <strong>Mar 15 (100%)</strong>.
            Penalty u/s 234B/234C: 1% per month on shortfall.
        </div>
    </div>
</div>

<!-- t136: AIS Reconciliation -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title">🔍 AIS / Form 26AS Reconciliation</h3>
        <span class="badge badge-info">Compare IT portal vs WealthDash</span>
    </div>
    <div class="card-body">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">
            Enter figures from your AIS on <a href="https://www.incometax.gov.in" target="_blank" style="color:var(--accent);">incometax.gov.in</a> to spot discrepancies before filing ITR.
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">AIS: Dividend Income (₹)</div>
                <input type="number" id="aisDividend" placeholder="0" class="adv-input" oninput="reconcileAIS()"></div>
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">AIS: Interest Income (₹)</div>
                <input type="number" id="aisInterest" placeholder="0" class="adv-input" oninput="reconcileAIS()"></div>
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">AIS: Securities Sale Proceeds (₹)</div>
                <input type="number" id="aisProceeds" placeholder="0" class="adv-input" oninput="reconcileAIS()"></div>
            <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">AIS: TDS Deducted (₹)</div>
                <input type="number" id="aisTds" placeholder="0" class="adv-input" oninput="reconcileAIS()"></div>
        </div>
        <div id="aisResult" style="display:none;"></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">
            💡 AIS data = what IT dept knows about you. Mismatches must be resolved before ITR filing.
        </div>
    </div>
</div>

<style>
.adv-input { width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box; }
</style>

<script>
function fmtAdvI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
}
function taxOnIncome(income, regime) {
    if (regime === 'new') {
        if (income <= 300000)  return 0;
        if (income <= 600000)  return (income-300000)*0.05;
        if (income <= 900000)  return 15000+(income-600000)*0.10;
        if (income <= 1200000) return 45000+(income-900000)*0.15;
        if (income <= 1500000) return 90000+(income-1200000)*0.20;
        return 150000+(income-1500000)*0.30;
    }
    if (income <= 250000)  return 0;
    if (income <= 500000)  return (income-250000)*0.05;
    if (income <= 1000000) return 12500+(income-500000)*0.20;
    return 112500+(income-1000000)*0.30;
}
function calcAdvTax() {
    const salary   = parseFloat(document.getElementById('advSalary')?.value)   || 0;
    const capGains = parseFloat(document.getElementById('advCapGains')?.value)  || 0;
    const interest = parseFloat(document.getElementById('advInterest')?.value)  || 0;
    const tdsPaid  = parseFloat(document.getElementById('advTdsPaid')?.value)   || 0;
    const regime   = document.getElementById('advRegime')?.value || 'new';
    const res      = document.getElementById('advTaxResult');
    if (!res || (!salary && !capGains && !interest)) { if(res) res.style.display='none'; return; }

    const totalTax      = taxOnIncome(salary, regime) + capGains*0.20 + interest*0.30;
    const cess          = totalTax * 0.04;
    const totalWithCess = totalTax + cess;
    const netDue        = Math.max(0, totalWithCess - tdsPaid);

    const today = new Date();
    const fy    = today.getMonth()+1 >= 4 ? today.getFullYear() : today.getFullYear()-1;
    const quarters = [
        {label:'Q1',deadline:new Date(fy,5,15),   pct:0.15,name:'Jun 15'},
        {label:'Q2',deadline:new Date(fy,8,15),   pct:0.45,name:'Sep 15'},
        {label:'Q3',deadline:new Date(fy,11,15),  pct:0.75,name:'Dec 15'},
        {label:'Q4',deadline:new Date(fy+1,2,15), pct:1.00,name:'Mar 15'},
    ];
    const nextQ = quarters.find(q => q.deadline >= today);
    const badge = document.getElementById('advTaxUrgencyBadge');
    if (nextQ && badge) {
        const days = Math.ceil((nextQ.deadline - today)/86400000);
        badge.textContent = `Next: ${nextQ.name} (${days} days)`;
        badge.className   = 'badge ' + (days<=14?'badge-danger':days<=30?'badge-warning':'badge-info');
    }
    res.style.display = '';
    res.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;">
      <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Total Tax + Cess</div>
        <div style="font-size:18px;font-weight:800;color:var(--accent);">${fmtAdvI(totalWithCess)}</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">TDS Already Paid</div>
        <div style="font-size:18px;font-weight:800;color:#16a34a;">${fmtAdvI(tdsPaid)}</div>
      </div>
      <div style="background:${netDue>0?'rgba(220,38,38,.07)':'rgba(22,163,74,.07)'};border-radius:8px;padding:12px;text-align:center;border:1.5px solid ${netDue>0?'#fca5a5':'#86efac'};">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Net Advance Tax Due</div>
        <div style="font-size:18px;font-weight:800;color:${netDue>0?'#dc2626':'#16a34a'};">${netDue>0?fmtAdvI(netDue):'NIL ✅'}</div>
      </div>
    </div>
    <table style="width:100%;font-size:12px;border-collapse:collapse;">
      <thead><tr style="border-bottom:2px solid var(--border);">
        <th style="padding:6px 8px;text-align:left;color:var(--text-muted);">Quarter</th>
        <th style="padding:6px 8px;text-align:left;color:var(--text-muted);">Deadline</th>
        <th style="padding:6px 8px;text-align:right;color:var(--text-muted);">Cumul %</th>
        <th style="padding:6px 8px;text-align:right;color:var(--text-muted);">Due Amount</th>
        <th style="padding:6px 8px;text-align:center;color:var(--text-muted);">Status</th>
      </tr></thead>
      <tbody>${quarters.map(q=>{
        const due=Math.max(0,netDue*q.pct);
        const past=q.deadline<today;
        const isNext=!past&&q===nextQ;
        return `<tr style="border-bottom:1px solid var(--border);">
          <td style="padding:6px 8px;font-weight:700;">${q.label}</td>
          <td style="padding:6px 8px;">${q.name}</td>
          <td style="padding:6px 8px;text-align:right;">${(q.pct*100).toFixed(0)}%</td>
          <td style="padding:6px 8px;text-align:right;font-weight:700;">${fmtAdvI(due)}</td>
          <td style="padding:6px 8px;text-align:center;">${past?'<span style="color:#16a34a;font-weight:700;">✓ Past</span>':isNext?'<span style="background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:4px;font-weight:700;font-size:11px;">⏰ Next Due</span>':'<span style="color:var(--text-muted);">Upcoming</span>'}</td>
        </tr>`;}).join('')}</tbody>
    </table>`;
}
function reconcileAIS() {
    const aisDividend = parseFloat(document.getElementById('aisDividend')?.value)||0;
    const aisInterest = parseFloat(document.getElementById('aisInterest')?.value)||0;
    const aisProceeds = parseFloat(document.getElementById('aisProceeds')?.value)||0;
    const aisTds      = parseFloat(document.getElementById('aisTds')?.value)||0;
    const res = document.getElementById('aisResult');
    if (!res||(!aisDividend&&!aisInterest&&!aisProceeds&&!aisTds)){if(res)res.style.display='none';return;}
    const rows = [
        {label:'Dividend Income',ais:aisDividend,wd:0},
        {label:'Interest Income',ais:aisInterest,wd:0},
        {label:'Sale Proceeds',  ais:aisProceeds,wd:0},
        {label:'TDS Deducted',   ais:aisTds,     wd:0},
    ];
    res.style.display='';
    res.innerHTML=`<table style="width:100%;font-size:12px;border-collapse:collapse;">
      <thead><tr style="border-bottom:2px solid var(--border);">
        <th style="padding:6px 8px;text-align:left;color:var(--text-muted);">Item</th>
        <th style="padding:6px 8px;text-align:right;color:var(--text-muted);">AIS (IT Portal)</th>
        <th style="padding:6px 8px;text-align:right;color:var(--text-muted);">WealthDash</th>
        <th style="padding:6px 8px;text-align:center;color:var(--text-muted);">Match</th>
      </tr></thead>
      <tbody>${rows.map(r=>`<tr style="border-bottom:1px solid var(--border);">
        <td style="padding:6px 8px;font-weight:600;">${r.label}</td>
        <td style="padding:6px 8px;text-align:right;">${fmtAdvI(r.ais)}</td>
        <td style="padding:6px 8px;text-align:right;color:var(--text-muted);">Enter in WD →</td>
        <td style="padding:6px 8px;text-align:center;color:var(--text-muted);">—</td>
      </tr>`).join('')}</tbody>
    </table>
    <div style="font-size:11px;color:var(--text-muted);margin-top:8px;padding:8px;background:rgba(59,130,246,.06);border-radius:6px;">
      💡 Compare each row with your WealthDash FY report. Discrepancies should be investigated before filing ITR.
    </div>`;
}
</script>

<!-- t138: Indexation Benefit Calculator -->
<div class="card mb-4" style="margin-top:20px;">
  <div class="card-header">
    <h3 class="card-title">📊 Indexation Benefit Calculator</h3>
    <span class="badge badge-info">Debt MF (pre-Apr 2023) · Real Estate</span>
  </div>
  <div class="card-body">
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">
      Indexed cost = Purchase Price × (CII of Sale Year / CII of Purchase Year).
      Compare: <strong>without indexation @12.5%</strong> vs <strong>with indexation @20%</strong> — choose lower tax.
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:14px;">
      <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Purchase Price (₹)</div>
        <input type="number" id="idxPurchase" placeholder="500000" class="adv-input" oninput="calcIndexation()"></div>
      <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Sale Price (₹)</div>
        <input type="number" id="idxSale" placeholder="900000" class="adv-input" oninput="calcIndexation()"></div>
      <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Purchase Year (FY)</div>
        <select id="idxPurchaseYear" class="adv-input" onchange="calcIndexation()">
          <option value="2001">2001-02</option><option value="2002">2002-03</option><option value="2003">2003-04</option>
          <option value="2004">2004-05</option><option value="2005">2005-06</option><option value="2006">2006-07</option>
          <option value="2007">2007-08</option><option value="2008">2008-09</option><option value="2009">2009-10</option>
          <option value="2010">2010-11</option><option value="2011">2011-12</option><option value="2012">2012-13</option>
          <option value="2013">2013-14</option><option value="2014">2014-15</option><option value="2015">2015-16</option>
          <option value="2016">2016-17</option><option value="2017">2017-18</option><option value="2018">2018-19</option>
          <option value="2019">2019-20</option><option value="2020">2020-21</option><option value="2021">2021-22</option>
          <option value="2022">2022-23</option><option value="2023" selected>2023-24</option>
        </select></div>
      <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Sale Year (FY)</div>
        <select id="idxSaleYear" class="adv-input" onchange="calcIndexation()">
          <option value="2022">2022-23</option><option value="2023">2023-24</option>
          <option value="2024" selected>2024-25</option><option value="2025">2025-26</option>
        </select></div>
      <div><div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Asset Type</div>
        <select id="idxAssetType" class="adv-input" onchange="calcIndexation()">
          <option value="debt_mf">Debt MF (pre-Apr 2023)</option>
          <option value="real_estate">Real Estate</option>
          <option value="gold">Physical Gold / Bonds</option>
        </select></div>
    </div>
    <div id="idxResult" style="display:none;"></div>
  </div>
</div>

<style>
.adv-input { width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box; }
</style>
<script>
// CII Table: FY → CII value (CBDT official)
const CII_TABLE = {
  2001:100,2002:105,2003:109,2004:113,2005:117,2006:122,2007:129,
  2008:137,2009:148,2010:167,2011:184,2012:200,2013:220,2014:240,
  2015:254,2016:264,2017:272,2018:280,2019:289,2020:301,2021:317,
  2022:331,2023:348,2024:363,2025:380
};
function calcIndexation() {
  const purchase  = parseFloat(document.getElementById('idxPurchase')?.value) || 0;
  const sale      = parseFloat(document.getElementById('idxSale')?.value) || 0;
  const pyear     = parseInt(document.getElementById('idxPurchaseYear')?.value) || 2020;
  const syear     = parseInt(document.getElementById('idxSaleYear')?.value) || 2024;
  const assetType = document.getElementById('idxAssetType')?.value || 'debt_mf';
  const res       = document.getElementById('idxResult');
  if (!res || !purchase || !sale) { if(res) res.style.display='none'; return; }

  const ciiP = CII_TABLE[pyear] || 100;
  const ciiS = CII_TABLE[syear] || 363;
  const indexedCost = purchase * (ciiS / ciiP);
  const ltcgWithIdx = Math.max(0, sale - indexedCost);
  const ltcgWithout = Math.max(0, sale - purchase);
  const taxWithIdx  = ltcgWithIdx * 0.20;   // 20% with indexation
  const taxWithout  = ltcgWithout * 0.125;  // 12.5% without indexation
  const benefit     = Math.max(0, taxWithout - taxWithIdx);
  const better      = taxWithIdx <= taxWithout ? 'indexation' : 'no_indexation';

  // Debt MF after Apr 2023: no indexation — slab rate
  const isDebtMF = assetType === 'debt_mf';

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
  }

  res.style.display = '';
  res.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
      <div style="background:${better==='no_indexation'?'rgba(22,163,74,.08)':'var(--bg-secondary)'};border-radius:10px;padding:14px;border:${better==='no_indexation'?'2px solid #86efac':'1.5px solid var(--border)'};">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Without Indexation @12.5%</div>
        <div style="font-size:11px;color:var(--text-muted);">LTCG: ${fmtI(ltcgWithout)}</div>
        <div style="font-size:18px;font-weight:800;color:#16a34a;">Tax: ${fmtI(taxWithout)}</div>
        ${better==='no_indexation'?'<div style="font-size:11px;font-weight:700;color:#16a34a;margin-top:4px;">✅ Better option</div>':''}
      </div>
      <div style="background:${better==='indexation'?'rgba(22,163,74,.08)':'var(--bg-secondary)'};border-radius:10px;padding:14px;border:${better==='indexation'?'2px solid #86efac':'1.5px solid var(--border)'};">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">With Indexation @20%</div>
        <div style="font-size:11px;color:var(--text-muted);">Indexed cost: ${fmtI(indexedCost)} · LTCG: ${fmtI(ltcgWithIdx)}</div>
        <div style="font-size:18px;font-weight:800;color:#3b82f6;">Tax: ${fmtI(taxWithIdx)}</div>
        ${better==='indexation'?'<div style="font-size:11px;font-weight:700;color:#16a34a;margin-top:4px;">✅ Better option</div>':''}
      </div>
    </div>
    <div style="background:${benefit>0?'rgba(22,163,74,.07)':'rgba(99,102,241,.06)'};border-radius:8px;padding:10px;font-size:12px;">
      ${benefit>0
        ? `💰 <strong>Indexation saves ₹${fmtI(benefit).replace('₹','')} in tax</strong> (CII: ${ciiP} → ${ciiS}, ratio: ${(ciiS/ciiP).toFixed(3)}x). Choose <strong>with indexation</strong>.`
        : `ℹ️ In this case, <strong>no indexation @12.5% is cheaper</strong>. This happens when returns are very high relative to inflation.`}
      ${isDebtMF ? '<br>⚠️ <strong>Debt MF purchased after Apr 1 2023:</strong> No LTCG option — taxed at slab rate.' : ''}
    </div>`;
}
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
