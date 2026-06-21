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

<!-- t438: HRA Exemption Calculator ─────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">🏠 HRA Exemption Calculator
      <span style="font-size:11px;font-weight:600;color:var(--text-muted);margin-left:6px;">Section 10(13A)</span>
    </h3>
  </div>
  <div class="card-body">
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 16px;">
      HRA exemption = <strong>minimum</strong> of three components below.
      Whichever is lowest becomes your exempt amount.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px;margin-bottom:16px;">
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Basic Salary — Annual (₹)</div>
        <input type="number" id="hraBasic" class="form-input" placeholder="e.g. 600000" oninput="calcHRA()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">HRA Received — Annual (₹)</div>
        <input type="number" id="hraReceived" class="form-input" placeholder="e.g. 240000" oninput="calcHRA()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Rent Paid — Annual (₹)</div>
        <input type="number" id="hraRentPaid" class="form-input" placeholder="e.g. 300000" oninput="calcHRA()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">City Type</div>
        <select id="hraCity" class="form-select" onchange="calcHRA()">
          <option value="50">Metro (Delhi/Mumbai/Kolkata/Chennai) — 50%</option>
          <option value="40">Non-Metro — 40%</option>
        </select>
      </div>
    </div>

    <!-- Three component breakdown -->
    <div id="hraComponents" style="display:none;">
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px;">
        <div id="hraComp1Card" style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;border:1.5px solid var(--border);">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">① Actual HRA</div>
          <div id="hraComp1Val" style="font-size:15px;font-weight:800;">—</div>
        </div>
        <div id="hraComp2Card" style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;border:1.5px solid var(--border);">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">② Rent − 10% Basic</div>
          <div id="hraComp2Val" style="font-size:15px;font-weight:800;">—</div>
        </div>
        <div id="hraComp3Card" style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;border:1.5px solid var(--border);">
          <div id="hraComp3Label" style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">③ 50% of Basic</div>
          <div id="hraComp3Val" style="font-size:15px;font-weight:800;">—</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div id="hraExemptCard" style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;padding:14px;">
          <div style="font-size:11px;font-weight:700;color:#065f46;text-transform:uppercase;margin-bottom:4px;">✅ HRA Exempt (Min of ①②③)</div>
          <div id="hraExemptVal" style="font-size:22px;font-weight:800;color:#065f46;">₹0</div>
          <div id="hraWinnerLabel" style="font-size:11px;color:#047857;margin-top:3px;">—</div>
        </div>
        <div id="hraTaxableCard" style="border-radius:8px;padding:14px;border:1px solid var(--border);background:var(--bg-secondary);">
          <div id="hraTaxableLabel" style="font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:4px;color:var(--text-muted);">Taxable HRA</div>
          <div id="hraTaxableVal" style="font-size:22px;font-weight:800;">₹0</div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Added to gross income</div>
        </div>
      </div>
      <div id="hraPanNote" style="display:none;padding:9px 12px;background:rgba(245,158,11,.1);border-radius:6px;font-size:12px;color:#92400e;">
        ⚠️ Rent &gt; ₹1,00,000/year — <strong>Landlord PAN is mandatory.</strong> Submit Form 12BB to your employer with the PAN.
      </div>
      <div style="margin-top:10px;padding:9px 12px;background:var(--bg-secondary);border-radius:6px;font-size:12px;color:var(--text-muted);">
        💡 <strong>Home loan vs HRA:</strong> If you pay both rent and home loan, compare: HRA exemption here vs u/s 24b interest deduction (₹2L limit) — only one set applies; choose whichever gives more benefit.
      </div>
    </div>
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

// t438: HRA Exemption Calculator
function calcHRA() {
  const basic    = parseFloat(document.getElementById('hraBasic')?.value)    || 0;
  const received = parseFloat(document.getElementById('hraReceived')?.value) || 0;
  const rent     = parseFloat(document.getElementById('hraRentPaid')?.value)  || 0;
  const cityPct  = parseFloat(document.getElementById('hraCity')?.value)     || 50;
  const fmtI     = v => '₹' + Math.round(v).toLocaleString('en-IN');
  const wrap     = document.getElementById('hraComponents');
  if (!wrap) return;
  if (!basic || !received || !rent) { wrap.style.display = 'none'; return; }

  const comp1   = received;
  const comp2   = Math.max(0, rent - 0.10 * basic);
  const comp3   = (cityPct / 100) * basic;
  const exempt  = Math.min(comp1, comp2, comp3);
  const taxable = Math.max(0, received - exempt);
  const winIdx  = [comp1, comp2, comp3].indexOf(exempt);
  const labels  = ['Actual HRA received', 'Rent − 10% of Basic', `${cityPct}% of Basic`];
  const highlight = 'border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,.15);';

  document.getElementById('hraComp1Val').textContent  = fmtI(comp1);
  document.getElementById('hraComp2Val').textContent  = fmtI(comp2);
  document.getElementById('hraComp3Val').textContent  = fmtI(comp3);
  document.getElementById('hraComp3Label').textContent = `③ ${cityPct}% of Basic`;
  document.getElementById('hraComp1Card').style.cssText += winIdx===0 ? highlight : '';
  document.getElementById('hraComp2Card').style.cssText += winIdx===1 ? highlight : '';
  document.getElementById('hraComp3Card').style.cssText += winIdx===2 ? highlight : '';
  document.getElementById('hraExemptVal').textContent   = fmtI(exempt);
  document.getElementById('hraWinnerLabel').textContent = `Limited by: ${labels[winIdx]}`;
  document.getElementById('hraTaxableVal').textContent  = fmtI(taxable);

  const taxCard = document.getElementById('hraTaxableCard');
  const taxLbl  = document.getElementById('hraTaxableLabel');
  if (taxable > 0) {
    taxCard.style.background = '#fee2e2'; taxCard.style.borderColor = '#fca5a5';
    taxLbl.style.color = '#b91c1c'; taxLbl.textContent = '⚠ Taxable HRA';
    document.getElementById('hraTaxableVal').style.color = '#dc2626';
  } else {
    taxCard.style.background = '#f0fdf4'; taxCard.style.borderColor = '#6ee7b7';
    taxLbl.style.color = '#065f46'; taxLbl.textContent = '✓ Fully Exempt';
    document.getElementById('hraTaxableVal').style.color = '#16a34a';
  }
  document.getElementById('hraPanNote').style.display = rent > 100000 ? 'block' : 'none';
  wrap.style.display = 'block';
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


<!-- t132: Grandfathering Calculator (Jan 31 2018 FMV) -->
<div class="card mb-4" style="margin-top:20px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <h3 class="card-title">⚡ Grandfathering Calculator — Jan 31, 2018 FMV Rule</h3>
    <span class="badge badge-warning">LTCG Budget 2018</span>
  </div>
  <div class="card-body">
    <div style="background:rgba(245,158,11,.07);border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;">
      💡 <strong>Grandfathering Rule:</strong> For equity/equity MF bought <strong>before Jan 31, 2018</strong>, the cost basis is the <em>higher</em> of actual purchase price or the Jan 31, 2018 FMV (Fair Market Value). This reduces your taxable LTCG.
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px;">
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Purchase Price (₹/unit)</div>
        <input type="number" id="gfPurchase" placeholder="e.g. 50" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcGrandfathering()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Jan 31, 2018 FMV (₹/unit)</div>
        <input type="number" id="gfFmv" placeholder="e.g. 120" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcGrandfathering()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Sale Price (₹/unit)</div>
        <input type="number" id="gfSale" placeholder="e.g. 180" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcGrandfathering()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Number of Units</div>
        <input type="number" id="gfUnits" placeholder="e.g. 1000" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcGrandfathering()">
      </div>
    </div>
    <div id="gfResult" style="display:none;"></div>
  </div>
</div>

<!-- t133: HIFO / Lot Selector — Tax-Optimal Cost Basis -->
<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">📦 HIFO Lot Selector — Tax-Optimal Cost Basis</h3>
    <span class="badge badge-info">Highest-In First-Out</span>
  </div>
  <div class="card-body">
    <div style="background:rgba(99,102,241,.06);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;">
      💡 <strong>HIFO</strong> = sell the lots with the <em>highest purchase price first</em>. This minimises your capital gain (and thus tax). Compare vs FIFO to see savings.
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:flex-end;">
      <div style="flex:1;min-width:140px;">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Sale Price (₹/unit)</div>
        <input type="number" id="hifoSalePrice" placeholder="e.g. 200" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcHifo()">
      </div>
      <div style="flex:1;min-width:140px;">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Units to Sell</div>
        <input type="number" id="hifoSellUnits" placeholder="e.g. 500" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcHifo()">
      </div>
      <button onclick="addHifoLot()" class="btn btn-outline btn-sm" style="height:38px;">+ Add Lot</button>
    </div>
    <!-- Lot Table -->
    <div style="overflow-x:auto;margin-bottom:14px;">
      <table class="data-table" id="hifoLotTable" style="font-size:12px;">
        <thead><tr><th>Lot #</th><th>Purchase Date</th><th>Cost/Unit (₹)</th><th>Units</th><th>Remove</th></tr></thead>
        <tbody id="hifoLotBody">
          <tr id="hifoEmptyRow"><td colspan="5" class="text-center" style="padding:16px;color:var(--text-muted);font-size:12px;">Click "+ Add Lot" to add purchase lots</td></tr>
        </tbody>
      </table>
    </div>
    <div id="hifoResult" style="display:none;"></div>
  </div>
</div>

<!-- t135: Capital Loss Set-Off & 8-Year Carry Forward -->
<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">📉 Capital Loss Set-Off & 8-Year Carry Forward</h3>
    <span class="badge badge-warning">Section 70–74</span>
  </div>
  <div class="card-body">
    <div style="background:rgba(99,102,241,.06);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;">
      💡 <strong>Rules:</strong> STCL can set off against STCG + LTCG. LTCL can set off only against LTCG. Unadjusted losses can be carried forward for <strong>8 assessment years</strong> if ITR is filed on time.
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:14px;">
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">This FY LTCG (₹)</div>
        <input type="number" id="lossLtcg" placeholder="0" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcLossSetoff()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">This FY STCG (₹)</div>
        <input type="number" id="lossStcg" placeholder="0" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcLossSetoff()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Brought-Fwd LTCL (₹)</div>
        <input type="number" id="lossBfLtcl" placeholder="0" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcLossSetoff()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Brought-Fwd STCL (₹)</div>
        <input type="number" id="lossBfStcl" placeholder="0" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcLossSetoff()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Current FY LTCL (₹)</div>
        <input type="number" id="lossCurrentLtcl" placeholder="0" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcLossSetoff()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Current FY STCL (₹)</div>
        <input type="number" id="lossCurrentStcl" placeholder="0" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcLossSetoff()">
      </div>
    </div>
    <div id="lossSetoffResult" style="display:none;"></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">
      ⚠️ File ITR before due date to carry forward losses. Losses lapse if ITR is filed late.
    </div>
  </div>
</div>

<!-- t137: Advance Tax Calculator -->
<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">📅 Advance Tax Calculator — Quarterly Estimates</h3>
    <span class="badge badge-info">Section 207–209</span>
  </div>
  <div class="card-body">
    <div style="background:rgba(99,102,241,.06);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;">
      💡 <strong>Advance Tax</strong> is due if total tax liability exceeds ₹10,000. Pay 15% by Jun 15, 45% by Sep 15, 75% by Dec 15, 100% by Mar 15. Interest @1%/month applies on shortfall.
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px;">
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Estimated Annual Income (₹)</div>
        <input type="number" id="atxIncome" placeholder="1200000" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcAdvTax()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">LTCG (equity, ₹)</div>
        <input type="number" id="atxLtcg" placeholder="0" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcAdvTax()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">STCG (equity, ₹)</div>
        <input type="number" id="atxStcg" placeholder="0" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcAdvTax()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Tax Regime</div>
        <select id="atxRegime" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" onchange="calcAdvTax()">
          <option value="new">New Regime (default)</option>
          <option value="old">Old Regime</option>
        </select>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Deductions 80C etc (₹)</div>
        <input type="number" id="atxDeductions" placeholder="150000" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcAdvTax()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Tax Already Paid (₹)</div>
        <input type="number" id="atxPaid" placeholder="0" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcAdvTax()">
      </div>
    </div>
    <div id="atxResult" style="display:none;"></div>
  </div>
</div>


<script>
/* ── t132: Grandfathering Calculator ─────────────────────────────────────── */
function calcGrandfathering() {
  const purchase = parseFloat(document.getElementById('gfPurchase')?.value) || 0;
  const fmv      = parseFloat(document.getElementById('gfFmv')?.value)      || 0;
  const sale     = parseFloat(document.getElementById('gfSale')?.value)      || 0;
  const units    = parseFloat(document.getElementById('gfUnits')?.value)     || 0;
  const res      = document.getElementById('gfResult');
  if (!res || !sale || !units) { if(res) res.style.display='none'; return; }

  // Grandfathered cost = max(purchase, min(fmv, sale))
  const effectiveCost = Math.max(purchase, Math.min(fmv || purchase, sale));
  const ltcgWithGF    = Math.max(0, sale - effectiveCost) * units;
  const ltcgWithout   = Math.max(0, sale - purchase) * units;
  const taxSaving     = Math.max(0, ltcgWithout - ltcgWithGF) * 0.125;
  const fmtI = v => v >= 1e7 ? '₹'+(v/1e7).toFixed(2)+'Cr' : v >= 1e5 ? '₹'+(v/1e5).toFixed(1)+'L' : '₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});

  const noFmv = !fmv;
  res.style.display = '';
  res.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Without Grandfathering</div>
        <div style="font-size:11px;color:var(--text-muted);">Cost basis: ₹${purchase}/unit</div>
        <div style="font-size:14px;font-weight:800;">LTCG: ${fmtI(ltcgWithout)}</div>
        <div style="font-size:13px;color:#dc2626;">Tax: ${fmtI(ltcgWithout * 0.125)}</div>
      </div>
      <div style="background:${taxSaving > 0 ? 'rgba(22,163,74,.08)' : 'var(--bg-secondary)'};border-radius:10px;padding:14px;border:${taxSaving > 0 ? '1.5px solid #86efac' : '1.5px solid var(--border)'};">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">With Grandfathering</div>
        <div style="font-size:11px;color:var(--text-muted);">Cost basis: ₹${effectiveCost.toFixed(2)}/unit ${noFmv ? '(FMV not entered)' : ''}</div>
        <div style="font-size:14px;font-weight:800;">LTCG: ${fmtI(ltcgWithGF)}</div>
        <div style="font-size:13px;color:#16a34a;">Tax: ${fmtI(ltcgWithGF * 0.125)}</div>
      </div>
    </div>
    ${taxSaving > 0
      ? `<div style="padding:10px;background:rgba(22,163,74,.07);border-radius:7px;font-size:12px;">💰 <strong>Grandfathering saves ₹${fmtI(taxSaving)} in tax</strong> — your effective cost basis is raised from ₹${purchase} to ₹${effectiveCost.toFixed(2)} using the Jan 31 2018 FMV.</div>`
      : `<div style="padding:10px;background:var(--bg-secondary);border-radius:7px;font-size:12px;">ℹ️ ${noFmv ? 'Enter Jan 31, 2018 FMV to calculate grandfathering benefit.' : 'Grandfathering has no benefit here — your purchase price is higher than or equal to the FMV.'}</div>`}`;
}

/* ── t133: HIFO Lot Selector ──────────────────────────────────────────────── */
let _hifoLots = [];
function addHifoLot() {
  const n    = _hifoLots.length + 1;
  const lot  = { id: n, date: '', cost: 0, units: 0 };
  _hifoLots.push(lot);
  renderHifoLotTable();
}
function removeHifoLot(id) {
  _hifoLots = _hifoLots.filter(l => l.id !== id);
  renderHifoLotTable();
  calcHifo();
}
function renderHifoLotTable() {
  const body = document.getElementById('hifoLotBody');
  if (!body) return;
  const empty = document.getElementById('hifoEmptyRow');
  if (empty) empty.remove();
  body.innerHTML = _hifoLots.map((l, i) => `
    <tr>
      <td style="font-weight:700;color:var(--text-muted);">#${i+1}</td>
      <td><input type="date" value="${l.date}" style="padding:4px 7px;border:1px solid var(--border);border-radius:5px;font-size:12px;background:var(--bg-secondary);color:var(--text-primary);" onchange="_hifoLots[${i}].date=this.value; calcHifo()"></td>
      <td><input type="number" placeholder="Cost/unit" value="${l.cost||''}" style="width:90px;padding:4px 7px;border:1px solid var(--border);border-radius:5px;font-size:12px;background:var(--bg-secondary);color:var(--text-primary);" oninput="_hifoLots[${i}].cost=+this.value; calcHifo()"></td>
      <td><input type="number" placeholder="Units" value="${l.units||''}" style="width:80px;padding:4px 7px;border:1px solid var(--border);border-radius:5px;font-size:12px;background:var(--bg-secondary);color:var(--text-primary);" oninput="_hifoLots[${i}].units=+this.value; calcHifo()"></td>
      <td><button onclick="removeHifoLot(${l.id})" style="border:none;background:none;color:#dc2626;cursor:pointer;font-size:16px;">✕</button></td>
    </tr>`).join('');
}
function calcHifo() {
  const salePrice  = parseFloat(document.getElementById('hifoSalePrice')?.value) || 0;
  const sellUnits  = parseFloat(document.getElementById('hifoSellUnits')?.value) || 0;
  const res        = document.getElementById('hifoResult');
  if (!res || !salePrice || !sellUnits || !_hifoLots.length) { if(res) res.style.display='none'; return; }

  const fmtI = v => v >= 1e7 ? '₹'+(v/1e7).toFixed(2)+'Cr' : v >= 1e5 ? '₹'+(v/1e5).toFixed(1)+'L' : '₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});

  // FIFO: oldest first
  const fifo = [..._hifoLots].filter(l => l.units > 0 && l.cost >= 0).sort((a,b) => (a.date||'9999') < (b.date||'9999') ? -1 : 1);
  // HIFO: highest cost first
  const hifo = [..._hifoLots].filter(l => l.units > 0 && l.cost >= 0).sort((a,b) => b.cost - a.cost);

  function calcGain(lots) {
    let remain = sellUnits, totalCost = 0, usedUnits = 0;
    for (const l of lots) {
      if (remain <= 0) break;
      const use = Math.min(l.units, remain);
      totalCost += use * l.cost;
      usedUnits += use;
      remain -= use;
    }
    if (remain > 0) return null; // not enough units
    return { cost: totalCost, gain: salePrice * usedUnits - totalCost };
  }

  const fifoRes = calcGain(fifo);
  const hifoRes = calcGain(hifo);
  if (!fifoRes || !hifoRes) { res.style.display='none'; return; }

  const saving = Math.max(0, fifoRes.gain - hifoRes.gain) * 0.125;

  res.style.display = '';
  res.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">FIFO (Default)</div>
        <div style="font-size:11px;color:var(--text-muted);">Total cost: ${fmtI(fifoRes.cost)}</div>
        <div style="font-size:16px;font-weight:800;">Gain: ${fmtI(fifoRes.gain)}</div>
        <div style="font-size:13px;color:#dc2626;">Tax ~${fmtI(fifoRes.gain * 0.125)}</div>
      </div>
      <div style="background:rgba(22,163,74,.08);border-radius:10px;padding:14px;border:1.5px solid #86efac;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">HIFO (Tax Optimal) ✅</div>
        <div style="font-size:11px;color:var(--text-muted);">Total cost: ${fmtI(hifoRes.cost)}</div>
        <div style="font-size:16px;font-weight:800;">Gain: ${fmtI(hifoRes.gain)}</div>
        <div style="font-size:13px;color:#16a34a;">Tax ~${fmtI(hifoRes.gain * 0.125)}</div>
      </div>
    </div>
    ${saving > 0 ? `<div style="padding:10px;background:rgba(22,163,74,.07);border-radius:7px;font-size:12px;">💰 <strong>HIFO saves ~${fmtI(saving)} in tax</strong> by selling highest-cost lots first. India FIFO is the default, but HIFO is better for tax planning — consult your CA.</div>` : '<div style="padding:10px;background:var(--bg-secondary);border-radius:7px;font-size:12px;">Both methods give the same result for these lots.</div>'}`;
}

/* ── t135: Capital Loss Set-Off ───────────────────────────────────────────── */
function calcLossSetoff() {
  const ltcg     = parseFloat(document.getElementById('lossLtcg')?.value)      || 0;
  const stcg     = parseFloat(document.getElementById('lossStcg')?.value)      || 0;
  const bfLtcl   = parseFloat(document.getElementById('lossBfLtcl')?.value)    || 0;
  const bfStcl   = parseFloat(document.getElementById('lossBfStcl')?.value)    || 0;
  const curLtcl  = parseFloat(document.getElementById('lossCurrentLtcl')?.value)|| 0;
  const curStcl  = parseFloat(document.getElementById('lossCurrentStcl')?.value)|| 0;
  const res      = document.getElementById('lossSetoffResult');
  if (!res) return;

  const fmtI = v => { const abs = Math.abs(v); return (v < 0 ? '-' : '') + (abs >= 1e5 ? '₹'+(abs/1e5).toFixed(1)+'L' : '₹'+abs.toLocaleString('en-IN',{maximumFractionDigits:0})); };

  // Step 1: Current year STCL sets off STCG first, then LTCG
  let netStcg = stcg - curStcl;
  let remainStcl = 0;
  let ltcgAfterStcl = ltcg;
  if (netStcg < 0) { remainStcl = -netStcg; netStcg = 0; ltcgAfterStcl = Math.max(0, ltcg - remainStcl); remainStcl = Math.max(0, remainStcl - ltcg); }

  // Step 2: Current year LTCL sets off only LTCG
  let netLtcg = ltcgAfterStcl - curLtcl;
  let remainLtcl = 0;
  if (netLtcg < 0) { remainLtcl = -netLtcg; netLtcg = 0; }

  // Step 3: Brought-forward STCL sets off remaining STCG, then LTCG
  let afterBfStcl_stcg = Math.max(0, netStcg - bfStcl);
  let bfStcl_excessToLtcg = Math.max(0, bfStcl - netStcg);
  let afterBfStcl_ltcg = Math.max(0, netLtcg - bfStcl_excessToLtcg);

  // Step 4: Brought-forward LTCL sets off only LTCG
  let finalLtcg = Math.max(0, afterBfStcl_ltcg - bfLtcl);
  let finalStcg = afterBfStcl_stcg;

  // LTCG exemption
  const EXEMPTION = 125000;
  const taxableLtcg = Math.max(0, finalLtcg - EXEMPTION);
  const ltcgTax = taxableLtcg * 0.125;
  const stcgTax = finalStcg * 0.20;
  const totalTax = ltcgTax + stcgTax;

  // Carry forward
  const cfLtcl = remainLtcl;
  const cfStcl = remainStcl;

  res.style.display = '';
  res.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px;">
      ${[
        ['Net Taxable LTCG', fmtI(finalLtcg), finalLtcg > 0 ? '#dc2626' : '#16a34a'],
        ['Net Taxable STCG', fmtI(finalStcg), finalStcg > 0 ? '#dc2626' : '#16a34a'],
        ['LTCG Tax (@12.5%)', fmtI(ltcgTax), '#d97706'],
        ['STCG Tax (@20%)', fmtI(stcgTax), '#d97706'],
        ['Total Tax', fmtI(totalTax), '#dc2626'],
        ['Carry Fwd LTCL', cfLtcl > 0 ? fmtI(cfLtcl) : '₹0', cfLtcl > 0 ? '#16a34a' : 'var(--text-muted)'],
      ].map(([label, val, color]) => `
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">${label}</div>
          <div style="font-size:15px;font-weight:800;color:${color};">${val}</div>
        </div>`).join('')}
    </div>
    ${(cfLtcl > 0 || cfStcl > 0) ? `
    <div style="padding:10px;background:rgba(22,163,74,.07);border-radius:7px;font-size:12px;">
      ✅ <strong>Carry Forward:</strong> 
      ${cfLtcl > 0 ? `LTCL ${fmtI(cfLtcl)} can be carried forward for 8 years.` : ''}
      ${cfStcl > 0 ? `STCL ${fmtI(cfStcl)} can be carried forward for 8 years.` : ''}
      <strong>File ITR before due date</strong> to preserve this benefit.
    </div>` : ''}`;
}

/* ── t137: Advance Tax Calculator ────────────────────────────────────────── */
function calcAdvTax() {
  const income     = parseFloat(document.getElementById('atxIncome')?.value)     || 0;
  const ltcg       = parseFloat(document.getElementById('atxLtcg')?.value)       || 0;
  const stcg       = parseFloat(document.getElementById('atxStcg')?.value)       || 0;
  const regime     = document.getElementById('atxRegime')?.value || 'new';
  const deductions = parseFloat(document.getElementById('atxDeductions')?.value) || 0;
  const paid       = parseFloat(document.getElementById('atxPaid')?.value)       || 0;
  const res        = document.getElementById('atxResult');
  if (!res || !income) { if(res) res.style.display='none'; return; }

  const fmtI = v => v >= 1e5 ? '₹'+(v/1e5).toFixed(1)+'L' : '₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});

  // Slab tax (simplified)
  function slabTax(inc, r) {
    if (r === 'new') {
      if (inc <= 300000) return 0;
      if (inc <= 700000) return (inc - 300000) * 0.05;
      if (inc <= 1000000) return 20000 + (inc - 700000) * 0.10;
      if (inc <= 1200000) return 50000 + (inc - 1000000) * 0.15;
      if (inc <= 1500000) return 80000 + (inc - 1200000) * 0.20;
      return 140000 + (inc - 1500000) * 0.30;
    } else {
      if (inc <= 250000) return 0;
      if (inc <= 500000) return (inc - 250000) * 0.05;
      if (inc <= 1000000) return 12500 + (inc - 500000) * 0.20;
      return 112500 + (inc - 1000000) * 0.30;
    }
  }

  const taxableIncome = Math.max(0, income - (regime === 'old' ? deductions : 75000)); // 75K std deduction new regime
  const slabT  = slabTax(taxableIncome, regime);
  const ltcgTax = Math.max(0, ltcg - 125000) * 0.125;
  const stcgTax = stcg * 0.20;
  const grossTax = slabT + ltcgTax + stcgTax;
  const cess    = grossTax * 0.04;
  const totalTax = grossTax + cess;
  const netDue  = Math.max(0, totalTax - paid);

  // Quarterly installments (% of total liability)
  const quarters = [
    { name: 'Q1 (by Jun 15)', pct: 0.15, due: '15-Jun' },
    { name: 'Q2 (by Sep 15)', pct: 0.30, due: '15-Sep' },
    { name: 'Q3 (by Dec 15)', pct: 0.30, due: '15-Dec' },
    { name: 'Q4 (by Mar 15)', pct: 0.25, due: '15-Mar' },
  ];

  res.style.display = '';
  res.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px;">
      <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Total Tax Liability</div>
        <div style="font-size:16px;font-weight:800;">${fmtI(totalTax)}</div>
        <div style="font-size:10px;color:var(--text-muted);">incl. 4% cess</div>
      </div>
      <div style="background:rgba(220,38,38,.07);border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Balance Due</div>
        <div style="font-size:16px;font-weight:800;color:${netDue > 0 ? '#dc2626' : '#16a34a'};">${fmtI(netDue)}</div>
      </div>
      ${totalTax < 10000 ? '<div style="grid-column:1/-1;padding:10px;background:rgba(22,163,74,.07);border-radius:7px;font-size:12px;">✅ Total tax &lt; ₹10,000 — Advance tax not applicable.</div>' : ''}
    </div>
    ${totalTax >= 10000 ? `
    <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Quarterly Advance Tax Schedule</div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;">
      ${quarters.map(q => {
        const amt = Math.round(netDue * q.pct);
        return `<div style="background:var(--bg-secondary);border-radius:8px;padding:10px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">${q.name}</div>
          <div style="font-size:15px;font-weight:800;color:#3b82f6;">${fmtI(amt)}</div>
          <div style="font-size:10px;color:var(--text-muted);">${(q.pct*100).toFixed(0)}% of due</div>
        </div>`;
      }).join('')}
    </div>
    <div style="margin-top:10px;padding:8px;background:rgba(220,38,38,.06);border-radius:6px;font-size:11px;color:var(--text-muted);">
      ⚠️ Late payment interest: 1% per month (Section 234C) on shortfall per quarter.
    </div>` : ''}`;
}
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     t286 — Schedule 112A: ITR LTCG Table (Equity MF)
     ════════════════════════════════════════════════════════════════════════ -->
<div class="card mb-4" id="card112A">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div>
            <h3 class="card-title">📋 Schedule 112A — ITR LTCG / STCG Statement</h3>
            <small class="text-secondary">ISIN-wise equity MF gains for ITR-2 / ITR-3. Grandfathering applied for pre-Jan 2018 units.</small>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select id="fy112A" class="form-select" style="width:120px;">
                <option value="2025-26">FY 2025-26</option>
                <option value="2024-25">FY 2024-25</option>
                <option value="2023-24">FY 2023-24</option>
                <option value="2022-23">FY 2022-23</option>
            </select>
            <button class="btn btn-secondary" onclick="load112A()" id="btn112ALoad">Load</button>
            <button class="btn btn-primary" onclick="export112AExcel()" id="btn112AExcel" style="display:none;">⬇ Export Excel</button>
            <button class="btn btn-secondary" onclick="copy112AText()" id="btn112ACopy" style="display:none;">📋 Copy CSV</button>
        </div>
    </div>
    <div class="card-body">
        <!-- Summary Banner -->
        <div id="s112ASummary" style="display:none;margin-bottom:16px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:12px;">
                <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
                    <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">LTCG Gains</div>
                    <div id="s112ALtcg" style="font-size:17px;font-weight:800;color:#10b981;">₹0</div>
                </div>
                <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
                    <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Exempt (₹1.25L)</div>
                    <div id="s112AExempt" style="font-size:17px;font-weight:800;color:#3b82f6;">₹0</div>
                </div>
                <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
                    <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Taxable LTCG</div>
                    <div id="s112ATaxableLtcg" style="font-size:17px;font-weight:800;color:#f59e0b;">₹0</div>
                </div>
                <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
                    <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">STCG Gains</div>
                    <div id="s112AStcg" style="font-size:17px;font-weight:800;color:#f59e0b;">₹0</div>
                </div>
                <div style="background:rgba(220,38,38,.08);border-radius:8px;padding:12px;text-align:center;border:1.5px solid rgba(220,38,38,.2);">
                    <div style="font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;margin-bottom:4px;">Est. Total Tax</div>
                    <div id="s112ATax" style="font-size:17px;font-weight:800;color:#dc2626;">₹0</div>
                </div>
            </div>
            <div id="s112ARateNote" style="font-size:11px;color:var(--text-muted);padding:8px 12px;background:var(--bg-secondary);border-radius:6px;"></div>
        </div>

        <!-- Table -->
        <div style="overflow-x:auto;">
            <table class="data-table" id="tbl112A" style="display:none;">
                <thead>
                    <tr>
                        <th style="min-width:220px;">Fund / ISIN</th>
                        <th>Purchase Date</th>
                        <th>Sale Date</th>
                        <th class="text-right">Units Sold</th>
                        <th class="text-right">Sale NAV (₹)</th>
                        <th class="text-right">Sale Amount (₹)</th>
                        <th class="text-right">Cost of Acq. (₹)</th>
                        <th class="text-right">Gain / Loss (₹)</th>
                        <th>Type</th>
                        <th class="text-right">Tax Rate</th>
                    </tr>
                </thead>
                <tbody id="tbody112A"></tbody>
                <tfoot id="tfoot112A"></tfoot>
            </table>
        </div>

        <!-- Empty / Loading state -->
        <div id="s112AEmpty" style="text-align:center;padding:40px;color:var(--text-muted);">
            <div style="font-size:32px;margin-bottom:8px;">📋</div>
            <div style="font-size:14px;font-weight:600;">Select FY and click Load to generate Schedule 112A</div>
            <div style="font-size:12px;margin-top:4px;">Shows equity MF gains with FIFO cost basis and Jan 31 2018 grandfathering</div>
        </div>
        <div id="s112ALoading" style="display:none;text-align:center;padding:40px;color:var(--text-muted);">
            <div style="font-size:14px;font-weight:600;">⏳ Calculating gains with FIFO…</div>
        </div>
    </div>
</div>

<script>
// ── t286: Schedule 112A ──────────────────────────────────────────────────
let _data112A = [];

function fmtI(v) {
    if (v === null || v === undefined) return '₹0';
    const n = parseFloat(v) || 0;
    return '₹' + Math.abs(n).toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0});
}

async function load112A() {
    const fy = document.getElementById('fy112A').value;
    document.getElementById('s112AEmpty').style.display = 'none';
    document.getElementById('s112ALoading').style.display = 'block';
    document.getElementById('tbl112A').style.display = 'none';
    document.getElementById('s112ASummary').style.display = 'none';
    document.getElementById('btn112AExcel').style.display = 'none';
    document.getElementById('btn112ACopy').style.display = 'none';

    try {
        const res = await fetch(`/api/reports/fy_gains.php?action=schedule_112a&fy=${fy}`);
        const data = await res.json();
        document.getElementById('s112ALoading').style.display = 'none';

        if (!data.success) {
            document.getElementById('s112AEmpty').style.display = 'block';
            document.getElementById('s112AEmpty').innerHTML = `<div style="color:#dc2626;font-size:13px;">Error: ${data.msg || 'Failed to load data'}</div>`;
            return;
        }

        _data112A = data.rows || [];
        const s = data.summary || {};

        // Populate summary
        document.getElementById('s112ALtcg').textContent       = fmtI(s.ltcg_total);
        document.getElementById('s112AExempt').textContent     = fmtI(s.ltcg_exempt);
        document.getElementById('s112ATaxableLtcg').textContent = fmtI(s.ltcg_taxable);
        document.getElementById('s112AStcg').textContent       = fmtI(s.stcg_total);
        document.getElementById('s112ATax').textContent        = fmtI(s.total_estimated_tax);
        document.getElementById('s112ARateNote').textContent   = s.note_rates || '';
        document.getElementById('s112ASummary').style.display  = 'block';

        if (_data112A.length === 0) {
            document.getElementById('s112AEmpty').style.display = 'block';
            document.getElementById('s112AEmpty').innerHTML = `<div style="font-size:14px;font-weight:600;color:var(--text-muted);">No equity MF sales found in FY ${fy}</div>`;
            return;
        }

        // Build table rows
        const tbody = document.getElementById('tbody112A');
        tbody.innerHTML = _data112A.map(r => {
            const gain = parseFloat(r.gain_loss);
            const gainColor = gain >= 0 ? '#10b981' : '#dc2626';
            const gainSign  = gain >= 0 ? '+' : '';
            const typeColor = r.gain_type === 'LTCG' ? '#3b82f6' : '#f59e0b';
            const grandBadge = r.grandfathered
                ? `<span style="font-size:9px;background:#fef3c7;color:#92400e;border-radius:4px;padding:1px 5px;margin-left:4px;">GF</span>` : '';
            return `<tr>
                <td>
                    <div style="font-size:12px;font-weight:700;color:var(--text-primary);">${r.fund_name}${grandBadge}</div>
                    <div style="font-size:10px;color:var(--text-muted);font-family:monospace;">${r.isin || '—'}</div>
                </td>
                <td style="font-size:12px;">${r.purchase_date || '—'}</td>
                <td style="font-size:12px;">${r.sale_date}</td>
                <td class="text-right" style="font-size:12px;">${parseFloat(r.units_sold).toFixed(4)}</td>
                <td class="text-right" style="font-size:12px;">₹${parseFloat(r.sale_nav).toFixed(4)}</td>
                <td class="text-right" style="font-size:12px;">${fmtI(r.sale_amount)}</td>
                <td class="text-right" style="font-size:12px;">${fmtI(r.cost_of_acquisition)}</td>
                <td class="text-right" style="font-size:13px;font-weight:700;color:${gainColor};">${gainSign}${fmtI(Math.abs(gain))}</td>
                <td><span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;background:${r.gain_type==='LTCG'?'rgba(59,130,246,.1)':'rgba(245,158,11,.1)'};color:${typeColor};">${r.gain_type}</span></td>
                <td class="text-right" style="font-size:12px;">${r.tax_rate_pct}%</td>
            </tr>`;
        }).join('');

        // Footer totals
        const ltcgRows = _data112A.filter(r => r.gain_type === 'LTCG');
        const stcgRows = _data112A.filter(r => r.gain_type === 'STCG');
        const sumGain = _data112A.reduce((a, r) => a + parseFloat(r.gain_loss), 0);
        document.getElementById('tfoot112A').innerHTML = `
            <tr style="background:var(--bg-secondary);font-weight:800;">
                <td colspan="7" style="font-size:12px;padding:10px 12px;">
                    Total (${_data112A.length} transactions · LTCG: ${ltcgRows.length} · STCG: ${stcgRows.length})
                </td>
                <td class="text-right" style="font-size:13px;color:${sumGain>=0?'#10b981':'#dc2626'};padding:10px 12px;">
                    ${sumGain>=0?'+':''}${fmtI(Math.abs(sumGain))}
                </td>
                <td colspan="2"></td>
            </tr>`;

        document.getElementById('tbl112A').style.display = '';
        document.getElementById('btn112AExcel').style.display = '';
        document.getElementById('btn112ACopy').style.display = '';

    } catch (e) {
        document.getElementById('s112ALoading').style.display = 'none';
        document.getElementById('s112AEmpty').style.display = 'block';
        document.getElementById('s112AEmpty').innerHTML = `<div style="color:#dc2626;">Failed to load Schedule 112A data.</div>`;
    }
}

function copy112AText() {
    const headers = ['ISIN','Fund Name','Purchase Date','Sale Date','Units Sold','Sale NAV','Sale Amount','Cost of Acquisition','Gain/Loss','Type','Tax Rate%'];
    const rows = _data112A.map(r => [
        r.isin, r.fund_name, r.purchase_date, r.sale_date,
        r.units_sold, r.sale_nav, r.sale_amount, r.cost_of_acquisition,
        r.gain_loss, r.gain_type, r.tax_rate_pct
    ].join('\t'));
    const text = [headers.join('\t'), ...rows].join('\n');
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('btn112ACopy');
        const orig = btn.textContent;
        btn.textContent = '✅ Copied!';
        setTimeout(() => btn.textContent = orig, 2000);
    });
}

function export112AExcel() {
    const fy = document.getElementById('fy112A').value;
    const headers = ['ISIN','Fund Name','Purchase Date','Sale Date','Units Sold','Sale NAV (₹)','Sale Amount (₹)','Cost of Acquisition (₹)','Gain/Loss (₹)','Type','Tax Rate %','Grandfathered'];
    const rows = _data112A.map(r => [
        r.isin || '', r.fund_name, r.purchase_date || '', r.sale_date,
        r.units_sold, r.sale_nav, r.sale_amount, r.cost_of_acquisition,
        r.gain_loss, r.gain_type, r.tax_rate_pct, r.grandfathered ? 'Yes' : 'No'
    ]);
    // Build CSV (BOM for Excel Indian encoding)
    const csvRows = [headers, ...rows].map(row =>
        row.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')
    );
    const csv = '\uFEFF' + csvRows.join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url;
    a.download = `Schedule_112A_${fy}_WealthDash.csv`;
    a.click(); URL.revokeObjectURL(url);
}

// Auto-load current FY on page load
(function() {
    const now = new Date();
    const curFY = now.getMonth() >= 3
        ? `${now.getFullYear()}-${String(now.getFullYear()-1999).padStart(2,'0')}`
        : `${now.getFullYear()-1}-${String(now.getFullYear()-2000).padStart(2,'0')}`;
    const sel = document.getElementById('fy112A');
    if (sel) {
        for (let i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === curFY) { sel.selectedIndex = i; break; }
        }
    }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     t377 — Tax Computation Sheet (CA-ready, print/PDF export)
     ════════════════════════════════════════════════════════════════════════ -->
<style>
@media print {
  body > *:not(#printWrap),
  nav, header, footer, .sidebar, .topbar, .breadcrumb,
  .page-header, .cards-grid, #taxSummaryCards,
  .card:not(#cardTaxComp), #card54EC, #card112A,
  .no-print { display: none !important; }
  #cardTaxComp { box-shadow: none !important; border: none !important; }
  #cardTaxComp .no-print { display: none !important; }
  .print-only { display: block !important; }
  @page { margin: 18mm 14mm; }
}
.print-only { display: none; }
#tblTaxComp td, #tblTaxComp th { padding: 9px 12px; font-size: 13px; border-bottom: 1px solid var(--border-color, #e5e7eb); }
#tblTaxComp .row-section { background: var(--bg-secondary, #f3f4f6); font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted, #6b7280); }
#tblTaxComp .row-total { font-weight: 800; font-size: 14px; }
#tblTaxComp .row-payable { background: rgba(220,38,38,.05); font-weight: 800; font-size: 15px; }
#tblTaxComp .row-refund  { background: rgba(16,185,129,.05); font-weight: 800; font-size: 15px; }
#tblTaxComp .amt { text-align: right; font-variant-numeric: tabular-nums; font-family: 'SF Mono', monospace, sans-serif; }
#tblTaxComp .indent { padding-left: 28px !important; color: var(--text-secondary, #4b5563); }
</style>

<!-- ═══════════════════════════════════════════════════════════════════════
     t437 — Tax P&L Statement — Complete Income Declaration
     ════════════════════════════════════════════════════════════════════════ -->
<div class="card mb-4" id="cardTaxPL">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
      <h3 class="card-title">📊 Tax P&amp;L Statement — Complete Income Declaration</h3>
      <small class="text-secondary">All capital gains · FD interest · Dividends · Estimated tax liability for the FY</small>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <select id="tplFY" class="form-select" style="width:130px;">
        <option value="2025-2026">FY 2025-26</option>
        <option value="2024-2025">FY 2024-25</option>
        <option value="2023-2024">FY 2023-24</option>
      </select>
      <button class="btn btn-primary" onclick="TaxPL.load()">⟳ Load Statement</button>
      <button class="btn btn-secondary no-print" onclick="window.print()">🖨 Print</button>
    </div>
  </div>
  <div class="card-body">
    <!-- Summary tiles -->
    <div id="tplSummaryTiles" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:20px;"></div>

    <!-- P&L Table -->
    <div id="tplTableWrap" style="display:none;">
      <table style="width:100%;border-collapse:collapse;font-size:13px;" id="tplTable">
        <thead>
          <tr style="background:var(--bg-secondary);border-bottom:2px solid var(--border);">
            <th style="padding:10px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);">Income Head</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);">Gross (₹)</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);">Exempt / Deduction</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);">Taxable (₹)</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);">Rate</th>
            <th style="padding:10px 12px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);">Est. Tax (₹)</th>
          </tr>
        </thead>
        <tbody id="tplTbody"></tbody>
      </table>
    </div>
    <div id="tplEmpty" style="text-align:center;padding:40px;color:var(--text-muted);font-size:14px;">
      ↑ Select a Financial Year and click <strong>Load Statement</strong>
    </div>
    <div id="tplLoading" style="display:none;text-align:center;padding:30px;color:var(--text-muted);">Loading P&amp;L data…</div>
    <div id="tplError" style="display:none;color:#dc2626;padding:14px;background:rgba(220,38,38,.05);border-radius:8px;margin-top:10px;"></div>
  </div>
</div>

<style>
#tplTable tr:hover { background: var(--bg-secondary); }
#tplTable .tpl-section { background: var(--bg-secondary); font-weight: 800; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); }
#tplTable .tpl-total { font-weight: 800; border-top: 2px solid var(--border); }
#tplTable .tpl-grand { background: rgba(99,102,241,.07); font-weight: 800; font-size: 14px; border-top: 2px solid var(--accent, #6366f1); }
#tplTable td, #tplTable th { padding: 9px 12px; border-bottom: 1px solid var(--border); }
#tplTable .ta-r { text-align: right; font-variant-numeric: tabular-nums; }
#tplTable .tax-red { color: #dc2626; font-weight: 700; }
#tplTable .tax-green { color: #16a34a; font-weight: 700; }
.tpl-tile { background: var(--bg-secondary); border-radius: 10px; padding: 14px 16px; text-align: center; }
.tpl-tile .t-label { font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 4px; }
.tpl-tile .t-val { font-size: 20px; font-weight: 800; }
</style>

<script>
const TaxPL = (() => {
  const INR = v => {
    v = Math.abs(+v || 0);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits: 0});
  };

  function row(label, gross, exempt, taxable, rate, estTax, cls='') {
    const fmt = v => (v === '' || v === null || v === undefined) ? '—' : INR(v);
    const taxCls = (estTax > 0) ? 'tax-red' : '';
    return `<tr class="${cls}">
      <td>${label}</td>
      <td class="ta-r">${fmt(gross)}</td>
      <td class="ta-r">${fmt(exempt)}</td>
      <td class="ta-r">${fmt(taxable)}</td>
      <td class="ta-r" style="color:var(--text-muted);font-size:12px;">${rate || '—'}</td>
      <td class="ta-r ${taxCls}">${fmt(estTax)}</td>
    </tr>`;
  }

  function sectionRow(label) {
    return `<tr class="tpl-section"><td colspan="6" style="padding:10px 12px;">${label}</td></tr>`;
  }

  async function load() {
    const fy = document.getElementById('tplFY').value;
    const [fyStart, fyEnd] = fy.split('-').map((y,i) => i===0 ? y+'-04-01' : y+'-03-31');

    document.getElementById('tplEmpty').style.display = 'none';
    document.getElementById('tplTableWrap').style.display = 'none';
    document.getElementById('tplError').style.display = 'none';
    document.getElementById('tplLoading').style.display = 'block';

    try {
      const resp = await fetch(`/api/tax/capital_gains.php?action=tax_pl_statement&fy=${fy.replace('-','-')}`);
      const data = await resp.json();
      document.getElementById('tplLoading').style.display = 'none';

      if (!data.success) throw new Error(data.message || 'Error loading data');

      const d = data.data;
      const stcg   = d.equity_stcg  || {};
      const ltcg   = d.equity_ltcg  || {};
      const debt   = d.debt_gains   || {};
      const fdInt  = d.fd_interest  || {};

      const stcgGross  = stcg.total_gain   || 0;
      const ltcgGross  = ltcg.total_gain   || 0;
      const ltcgExempt = Math.min(ltcgGross, 125000);
      const ltcgTax    = ltcg.estimated_tax || 0;
      const stcgTax    = stcg.estimated_tax || 0;
      const debtTax    = debt.total_gain    || 0;
      const fdAmt      = fdInt.amount       || 0;
      const totalTax   = ltcgTax + stcgTax;
      const grandTotal = totalTax;

      // Summary tiles
      document.getElementById('tplSummaryTiles').innerHTML = `
        <div class="tpl-tile"><div class="t-label">LTCG Gain</div><div class="t-val ${ltcgGross>=0?'tax-red':''}">${INR(ltcgGross)}</div></div>
        <div class="tpl-tile"><div class="t-label">STCG Gain</div><div class="t-val ${stcgGross>=0&&stcgGross>0?'tax-red':''}">${INR(stcgGross)}</div></div>
        <div class="tpl-tile"><div class="t-label">Debt / Other Gains</div><div class="t-val">${INR(debtTax)}</div></div>
        <div class="tpl-tile"><div class="t-label">FD Interest</div><div class="t-val">${INR(fdAmt)}</div></div>
        <div class="tpl-tile" style="background:rgba(220,38,38,.07);border:1.5px solid #fca5a5;">
          <div class="t-label">Est. Total Tax</div>
          <div class="t-val tax-red">${INR(grandTotal)}</div>
        </div>
      `;

      // Build table
      let html = '';
      html += sectionRow('A · EQUITY CAPITAL GAINS');
      html += row('LTCG — Equity MF / Stocks (held &gt;1 year)', ltcgGross, ltcgExempt, Math.max(0, ltcgGross - ltcgExempt), '12.5% + 4% cess', ltcgTax);
      html += row('STCG — Equity MF / Stocks (held &lt;1 year)', stcgGross, 0, stcgGross, '20% + 4% cess', stcgTax);
      html += row('<strong>Sub-total: Equity Capital Gains Tax</strong>', '', '', '', '', ltcgTax + stcgTax, 'tpl-total');

      html += sectionRow('B · DEBT &amp; OTHER CAPITAL GAINS');
      html += row('Debt Fund Gains (post Apr 2023 — slab rate)', debt.total_gain || 0, 0, debt.total_gain || 0, 'Slab rate', 0);
      html += `<tr><td colspan="6" style="padding:6px 12px;font-size:11px;color:var(--text-muted);">⚠️ Add ₹${INR(debt.total_gain||0)} to your total income — taxed at income slab rate. Cannot compute without knowing salary.</td></tr>`;

      html += sectionRow('C · INTEREST &amp; DIVIDEND INCOME');
      html += row('FD / Savings Interest (taxable)', fdAmt, 10000, Math.max(0, fdAmt - 10000), 'Slab rate', 0);
      html += `<tr><td colspan="6" style="padding:6px 12px;font-size:11px;color:var(--text-muted);">💡 80TTA deduction: ₹10,000 on savings interest. 80TTB for seniors: ₹50,000.</td></tr>`;

      html += sectionRow('D · TAX SUMMARY');
      html += row('<strong>Estimated Capital Gains Tax (incl. 4% cess)</strong>', '', '', '', '', ltcgTax + stcgTax, 'tpl-total');
      html += row('<strong>Add: Slab-rate income (salary + debt + FD) — manual entry needed</strong>', '', '', '', '—', 0, 'tpl-total');
      html += `<tr class="tpl-grand"><td colspan="5" style="padding:12px;"><strong>Grand Total Est. Tax (capital gains only)</strong></td><td class="ta-r tax-red" style="padding:12px;font-size:16px;">${INR(grandTotal)}</td></tr>`;

      document.getElementById('tplTbody').innerHTML = html;
      document.getElementById('tplTableWrap').style.display = '';
    } catch(e) {
      document.getElementById('tplLoading').style.display = 'none';
      document.getElementById('tplError').style.display = '';
      document.getElementById('tplError').textContent = '⚠️ ' + e.message;
    }
  }
  return { load };
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     t439 — Form 26AS Reconciler — Full TDS Matching
     ════════════════════════════════════════════════════════════════════════ -->
<div class="card mb-4" id="card26AS">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
      <h3 class="card-title">🔎 Form 26AS / AIS Reconciler — TDS Matching</h3>
      <small class="text-secondary">Enter figures from IT portal → spot discrepancies before ITR filing</small>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <a href="https://www.incometax.gov.in/iec/foportal/" target="_blank" class="btn btn-secondary" style="font-size:12px;">Open IT Portal ↗</a>
      <button class="btn btn-primary" onclick="Rec26AS.reconcile()">🔍 Reconcile</button>
    </div>
  </div>
  <div class="card-body">
    <div style="background:rgba(99,102,241,.06);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:12px;">
      📋 <strong>How to use:</strong> Download Form 26AS / AIS from <a href="https://www.incometax.gov.in" target="_blank" style="color:var(--accent);">incometax.gov.in</a> → Annual Information Statement → Enter the figures below → Click Reconcile to see mismatches.
    </div>

    <!-- Input grid -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;" id="rec26inputs">
      <div style="background:var(--bg-secondary);border-radius:8px;padding:14px;">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:10px;">📄 As per Form 26AS / AIS</div>
        <div style="display:grid;gap:8px;">
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">TDS from Salary / Employer (₹)</label>
            <input type="number" id="r26Salary" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">TDS on FD / Bank Interest (₹)</label>
            <input type="number" id="r26FD" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">TDS on Dividend (₹)</label>
            <input type="number" id="r26Div" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">Securities Sale Proceeds (₹)</label>
            <input type="number" id="r26Proceeds" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">Total TDS as per 26AS (₹)</label>
            <input type="number" id="r26TotalTDS" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">Total Interest Income in AIS (₹)</label>
            <input type="number" id="r26Interest" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
        </div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:8px;padding:14px;">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:10px;">📊 As per Your Records / WealthDash</div>
        <div style="display:grid;gap:8px;">
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">TDS from Salary (as per Form 16) (₹)</label>
            <input type="number" id="rWDSalary" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">TDS on FD (from your FD records) (₹)</label>
            <input type="number" id="rWDFD" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">TDS on Dividend (from broker) (₹)</label>
            <input type="number" id="rWDDiv" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">Actual Sale Proceeds (from broker) (₹)</label>
            <input type="number" id="rWDProceeds" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">Total TDS You Have Records Of (₹)</label>
            <input type="number" id="rWDTotalTDS" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
          <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">Actual Interest Income (₹)</label>
            <input type="number" id="rWDInterest" placeholder="0" value="0" class="rec26-inp" oninput="Rec26AS.reconcile()"></div>
        </div>
      </div>
    </div>

    <div id="rec26Result" style="display:none;"></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:10px;">
      ⚠️ <strong>Action needed if mismatch &gt; ₹100:</strong> Raise grievance on IT portal or contact TDS deductor before filing ITR. Excess TDS → refund; shortfall → penalty.
    </div>
  </div>
</div>

<style>
.rec26-inp { width:100%;padding:7px 10px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg);color:var(--text-primary);box-sizing:border-box; }
.rec26-row { display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;align-items:center;padding:8px 12px;border-bottom:1px solid var(--border);font-size:13px; }
.rec26-row:last-child { border-bottom:none; }
.mismatch-badge { display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700; }
.mismatch-ok { background:rgba(22,163,74,.12);color:#15803d; }
.mismatch-warn { background:rgba(234,179,8,.15);color:#854d0e; }
.mismatch-danger { background:rgba(220,38,38,.12);color:#b91c1c; }
</style>

<script>
const Rec26AS = (() => {
  const INR = v => { v = +v||0; const a=Math.abs(v); const s=v<0?'-':''; if(a>=1e7)return s+'₹'+(a/1e7).toFixed(2)+'Cr'; if(a>=1e5)return s+'₹'+(a/1e5).toFixed(1)+'L'; return s+'₹'+a.toLocaleString('en-IN',{maximumFractionDigits:0}); };
  const V = id => parseFloat(document.getElementById(id)?.value)||0;

  function reconcile() {
    const fields = [
      { label: 'TDS — Salary / Employer',      f26: V('r26Salary'),   wd: V('rWDSalary') },
      { label: 'TDS — FD / Bank Interest',      f26: V('r26FD'),       wd: V('rWDFD') },
      { label: 'TDS — Dividend',                f26: V('r26Div'),      wd: V('rWDDiv') },
      { label: 'Securities Sale Proceeds',      f26: V('r26Proceeds'), wd: V('rWDProceeds') },
      { label: 'Total TDS Deducted',            f26: V('r26TotalTDS'), wd: V('rWDTotalTDS') },
      { label: 'Interest Income',               f26: V('r26Interest'), wd: V('rWDInterest') },
    ];

    const hasData = fields.some(f => f.f26 > 0 || f.wd > 0);
    const res = document.getElementById('rec26Result');
    if (!hasData) { res.style.display='none'; return; }

    let rows = '';
    let totalMismatch = 0;
    fields.forEach(f => {
      const diff = f.f26 - f.wd;
      const adiff = Math.abs(diff);
      totalMismatch += adiff;
      const cls = adiff === 0 ? 'mismatch-ok' : adiff < 500 ? 'mismatch-warn' : 'mismatch-danger';
      const icon = adiff === 0 ? '✅ Match' : (diff > 0 ? `⚠️ 26AS higher by ${INR(adiff)}` : `⚠️ Your records higher by ${INR(adiff)}`);
      rows += `<div class="rec26-row">
        <span>${f.label}</span>
        <span style="text-align:right;">${INR(f.f26)}</span>
        <span style="text-align:right;">${INR(f.wd)}</span>
        <span><span class="mismatch-badge ${cls}">${icon}</span></span>
      </div>`;
    });

    const overallCls = totalMismatch === 0 ? 'mismatch-ok' : totalMismatch < 1000 ? 'mismatch-warn' : 'mismatch-danger';
    const tdsCredit = V('r26TotalTDS');
    res.style.display = '';
    res.innerHTML = `
      <div style="border:1.5px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px;">
        <div class="rec26-row" style="background:var(--bg-secondary);font-weight:700;font-size:11px;text-transform:uppercase;color:var(--text-muted);">
          <span>Item</span><span style="text-align:right;">Form 26AS / AIS</span><span style="text-align:right;">Your Records</span><span>Status</span>
        </div>
        ${rows}
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;">
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">TDS Credit Available</div>
          <div style="font-size:18px;font-weight:800;color:#16a34a;">${INR(tdsCredit)}</div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Deduct from tax payable in ITR</div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Total Mismatch</div>
          <div style="font-size:18px;font-weight:800;" class="${overallCls.replace('mismatch-','')==='ok'?'':'tax-red'}">${INR(totalMismatch)}</div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${totalMismatch===0?'✅ All matched':'Needs resolution before ITR'}</div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">ITR Pre-fill Status</div>
          <div style="font-size:16px;font-weight:800;">${totalMismatch===0?'✅ Ready':'⚠️ Resolve First'}</div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${totalMismatch===0?'26AS data matches your records':'Fix mismatches before submitting ITR'}</div>
        </div>
      </div>
      ${totalMismatch > 0 ? `<div style="margin-top:12px;padding:10px 14px;background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.3);border-radius:8px;font-size:12px;">
        <strong>📌 Action Steps:</strong><br>
        1. Log in to <a href="https://www.incometax.gov.in" target="_blank" style="color:var(--accent);">incometax.gov.in</a> → AIS → feedback → mark incorrect/not applicable<br>
        2. Contact the TDS deductor (employer/bank) to correct their TDS filing (TRACES)<br>
        3. If 26AS shows more TDS than you received credit → check with deductor<br>
        4. Deadline to update AIS feedback: before ITR filing date
      </div>` : ''}
    `;
  }
  return { reconcile };
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     t440 — Capital Gains Optimizer — Minimize Tax This FY
     ════════════════════════════════════════════════════════════════════════ -->
<div class="card mb-4" id="cardCGOptimizer">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
      <h3 class="card-title">⚡ Capital Gains Optimizer — Minimize Tax This FY</h3>
      <small class="text-secondary">LTCG harvest · STCG deferral · Loss set-off · Action plan ranked by tax saving</small>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <button class="btn btn-primary" id="cgoLoadBtn" onclick="CGO.load()">⟳ Load Opportunities</button>
    </div>
  </div>
  <div class="card-body">
    <div id="cgoLoading" style="display:none;text-align:center;padding:30px;color:var(--text-muted);">Analysing your portfolio for tax saving opportunities…</div>
    <div id="cgoEmpty" style="text-align:center;padding:40px;color:var(--text-muted);font-size:14px;">↑ Click <strong>Load Opportunities</strong> to analyse your portfolio</div>
    <div id="cgoResult" style="display:none;"></div>

    <!-- Manual override for quick scenario testing -->
    <details style="margin-top:16px;">
      <summary style="cursor:pointer;font-size:12px;font-weight:700;color:var(--text-muted);user-select:none;">⚙️ Manual Scenario — Test with custom values</summary>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-top:12px;padding:14px;background:var(--bg-secondary);border-radius:8px;">
        <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">LTCG Realised So Far (₹)</label>
          <input type="number" id="cgoManualLTCG" placeholder="0" value="0" class="rec26-inp" oninput="CGO.calcManual()"></div>
        <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">Unrealised LTCG in Portfolio (₹)</label>
          <input type="number" id="cgoManualUnreal" placeholder="0" value="0" class="rec26-inp" oninput="CGO.calcManual()"></div>
        <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">STCG This FY (₹)</label>
          <input type="number" id="cgoManualSTCG" placeholder="0" value="0" class="rec26-inp" oninput="CGO.calcManual()"></div>
        <div><label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">Capital Losses (₹)</label>
          <input type="number" id="cgoManualLoss" placeholder="0" value="0" class="rec26-inp" oninput="CGO.calcManual()"></div>
      </div>
      <div id="cgoManualResult" style="margin-top:10px;"></div>
    </details>
  </div>
</div>

<style>
.cgo-action { display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;margin-bottom:8px;background:var(--bg-secondary); }
.cgo-action .cgo-rank { width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0; }
.cgo-action .cgo-body { flex:1; }
.cgo-action .cgo-title { font-size:13px;font-weight:700;margin-bottom:3px; }
.cgo-action .cgo-desc { font-size:12px;color:var(--text-muted); }
.cgo-action .cgo-saving { font-size:14px;font-weight:800;color:#16a34a; }
</style>

<script>
const CGO = (() => {
  const INR = v => { v=+v||0; const a=Math.abs(v),s=v<0?'-':''; if(a>=1e7)return s+'₹'+(a/1e7).toFixed(2)+'Cr'; if(a>=1e5)return s+'₹'+(a/1e5).toFixed(1)+'L'; return s+'₹'+a.toLocaleString('en-IN',{maximumFractionDigits:0}); };
  const V = id => parseFloat(document.getElementById(id)?.value)||0;
  const EXEMPTION = 125000;

  function renderResult(ltcgRealised, exemptRemaining, harvestPlan, totalSaving, source='portfolio') {
    const el = document.getElementById(source==='manual'?'cgoManualResult':'cgoResult');
    const exemptUsed = Math.min(ltcgRealised, EXEMPTION);
    const pct = Math.min(100, (ltcgRealised/EXEMPTION)*100);
    const barColor = pct<60?'#16a34a':pct<90?'#f59e0b':'#dc2626';

    let actionsHtml = '';
    const colors = ['#6366f1','#3b82f6','#10b981','#f59e0b','#8b5cf6'];
    harvestPlan.forEach((h, i) => {
      actionsHtml += `<div class="cgo-action">
        <div class="cgo-rank" style="background:${colors[i%colors.length]}22;color:${colors[i%colors.length]};">#${i+1}</div>
        <div class="cgo-body">
          <div class="cgo-title">${h.fund_name || h.label}</div>
          <div class="cgo-desc">${h.action || h.desc}</div>
        </div>
        <div class="cgo-saving">Save ${INR(h.tax_saving_vs_later||h.saving||0)}</div>
      </div>`;
    });

    el.style.display = '';
    el.innerHTML = `
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px;">
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">LTCG Realised</div>
          <div style="font-size:18px;font-weight:800;">${INR(ltcgRealised)}</div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Exemption Left</div>
          <div style="font-size:18px;font-weight:800;color:#16a34a;">${INR(exemptRemaining)}</div>
        </div>
        <div style="background:rgba(22,163,74,.07);border:1.5px solid #86efac;border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Total Tax Saving Possible</div>
          <div style="font-size:18px;font-weight:800;color:#16a34a;">${INR(totalSaving)}</div>
        </div>
      </div>
      <div style="margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
          <span>LTCG Exemption Used (₹1,25,000)</span><span><strong>${INR(exemptUsed)}</strong> of ${INR(EXEMPTION)}</span>
        </div>
        <div style="height:10px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
          <div style="height:100%;width:${pct}%;background:${barColor};border-radius:99px;transition:width .6s;"></div>
        </div>
      </div>
      ${harvestPlan.length > 0 ? `
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;">🎯 Action Plan — Ranked by Tax Saving</div>
        ${actionsHtml}
        <div style="font-size:12px;color:var(--text-muted);padding:10px 14px;background:rgba(99,102,241,.05);border-radius:8px;margin-top:8px;">
          💡 <strong>Harvest & Reinvest Strategy:</strong> Redeem → same day reinvest in same fund → cost basis resets to higher NAV → future LTCG reduces. Zero exit tax if within ₹1.25L exemption.
        </div>
      ` : '<div style="padding:16px;text-align:center;color:#16a34a;font-weight:700;">✅ No harvest opportunities — exemption fully utilised or no eligible holdings.</div>'}
    `;
  }

  async function load() {
    document.getElementById('cgoEmpty').style.display = 'none';
    document.getElementById('cgoResult').style.display = 'none';
    document.getElementById('cgoLoading').style.display = '';
    try {
      const resp = await fetch('/api/tax/capital_gains.php?action=tax_capital_gains_optimize');
      const data = await resp.json();
      document.getElementById('cgoLoading').style.display = 'none';
      if (!data.success) throw new Error(data.message);
      const d = data.data;
      renderResult(d.ltcg_realised_so_far, d.exemption_remaining, d.harvest_opportunities||[], d.total_tax_saving||0);
    } catch(e) {
      document.getElementById('cgoLoading').style.display = 'none';
      document.getElementById('cgoResult').style.display = '';
      document.getElementById('cgoResult').innerHTML = `<div style="color:#dc2626;padding:12px;background:rgba(220,38,38,.05);border-radius:8px;">⚠️ ${e.message}</div>`;
    }
  }

  function calcManual() {
    const ltcgRealised = V('cgoManualLTCG');
    const unreal       = V('cgoManualUnreal');
    const stcg         = V('cgoManualSTCG');
    const loss         = V('cgoManualLoss');
    const exemptRemaining = Math.max(0, EXEMPTION - ltcgRealised);
    const harvestable  = Math.min(unreal, exemptRemaining);
    const taxSaving    = harvestable * 0.125;

    const plan = [];
    if (harvestable > 0) {
      plan.push({ fund_name: 'Unrealised Equity LTCG in Portfolio', action: `Harvest ${INR(harvestable)} → reinvest → cost basis resets → save ${INR(taxSaving)} in tax later`, tax_saving_vs_later: taxSaving });
    }
    if (loss > 0 && stcg > 0) {
      const setoff = Math.min(loss, stcg);
      plan.push({ fund_name: 'Loss Set-Off Against STCG', action: `Set off ${INR(setoff)} of losses against STCG → saves ${INR(setoff * 0.20)} in STCG tax`, tax_saving_vs_later: setoff * 0.20 });
    }
    if (stcg > 50000 && new Date().getMonth() >= 11) {
      plan.push({ fund_name: 'Defer STCG to Next FY', action: 'You are in Dec-Mar window — consider deferring redemptions to next FY to defer ₹'+INR(stcg*0.20)+' STCG tax by 1 year', tax_saving_vs_later: stcg * 0.02 });
    }

    renderResult(ltcgRealised, exemptRemaining, plan, plan.reduce((s,p)=>s+(p.tax_saving_vs_later||0),0), 'manual');
  }

  return { load, calcManual };
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     t441 — Advance Tax Planner — Full Quarterly Estimates
     ════════════════════════════════════════════════════════════════════════ -->
<div class="card mb-4" id="cardAdvTaxPlanner">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
      <h3 class="card-title">📅 Advance Tax Planner — Quarterly Estimates</h3>
      <small class="text-secondary">Section 207–209 · Penalty 234B/234C · Auto-compute from portfolio</small>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <span class="badge badge-warning" id="atpNextDueBadge" style="display:none;"></span>
      <button class="btn btn-secondary" onclick="ATP.autoFill()">🔄 Auto-fill from Portfolio</button>
      <button class="btn btn-primary" onclick="ATP.calc()">Calculate</button>
    </div>
  </div>
  <div class="card-body">
    <div style="background:rgba(234,179,8,.07);border:1px solid rgba(234,179,8,.3);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12px;">
      💡 <strong>Who must pay Advance Tax?</strong> Anyone with estimated tax liability &gt; ₹10,000 after TDS. Senior citizens (75+) with only pension/FD income are exempt.
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:16px;">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Estimated Annual Salary (₹)</label>
        <input type="number" id="atpSalary" placeholder="1200000" value="" class="rec26-inp" oninput="ATP.calc()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Business / Freelance Income (₹)</label>
        <input type="number" id="atpBusiness" placeholder="0" value="0" class="rec26-inp" oninput="ATP.calc()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">LTCG — Equity (₹)</label>
        <input type="number" id="atpLTCG" placeholder="0" value="0" class="rec26-inp" oninput="ATP.calc()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">STCG — Equity (₹)</label>
        <input type="number" id="atpSTCG" placeholder="0" value="0" class="rec26-inp" oninput="ATP.calc()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Interest / FD Income (₹)</label>
        <input type="number" id="atpInterest" placeholder="0" value="0" class="rec26-inp" oninput="ATP.calc()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">TDS Already Deducted (₹)</label>
        <input type="number" id="atpTDS" placeholder="0" value="0" class="rec26-inp" oninput="ATP.calc()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">80C / 80D / Other Deductions (₹)</label>
        <input type="number" id="atpDeductions" placeholder="150000" value="0" class="rec26-inp" oninput="ATP.calc()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Tax Regime</label>
        <select id="atpRegime" class="rec26-inp" onchange="ATP.calc()">
          <option value="new">New Regime (default)</option>
          <option value="old">Old Regime</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Advance Tax Already Paid (₹)</label>
        <input type="number" id="atpAlreadyPaid" placeholder="0" value="0" class="rec26-inp" oninput="ATP.calc()">
      </div>
    </div>

    <div id="atpResult" style="display:none;"></div>
    <div id="atpEmpty" style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px;">Enter your income details above and click <strong>Calculate</strong></div>
  </div>
</div>

<script>
const ATP = (() => {
  const INR = v => { v=+v||0; const a=Math.abs(v),s=v<0?'-':''; if(a>=1e7)return s+'₹'+(a/1e7).toFixed(2)+'Cr'; if(a>=1e5)return s+'₹'+(a/1e5).toFixed(1)+'L'; return s+'₹'+a.toLocaleString('en-IN',{maximumFractionDigits:0}); };
  const V = id => parseFloat(document.getElementById(id)?.value)||0;

  function taxSlab(income, regime) {
    if (regime === 'new') {
      // New regime FY2024-25+
      if (income <= 300000) return 0;
      if (income <= 700000) return (income-300000)*0.05;
      if (income <= 1000000) return 20000+(income-700000)*0.10;
      if (income <= 1200000) return 50000+(income-1000000)*0.15;
      if (income <= 1500000) return 80000+(income-1200000)*0.20;
      return 140000+(income-1500000)*0.30;
    }
    // Old regime
    if (income <= 250000) return 0;
    if (income <= 500000) return (income-250000)*0.05;
    if (income <= 1000000) return 12500+(income-500000)*0.20;
    return 112500+(income-1000000)*0.30;
  }

  function rebate87A(tax, income, regime) {
    // Rebate u/s 87A: if total income ≤ 7L (new) or 5L (old), tax=0
    if (regime==='new' && income<=700000) return 0;
    if (regime==='old' && income<=500000) return 0;
    return tax;
  }

  function calc() {
    const salary     = V('atpSalary');
    const business   = V('atpBusiness');
    const ltcg       = V('atpLTCG');
    const stcg       = V('atpSTCG');
    const interest   = V('atpInterest');
    const tds        = V('atpTDS');
    const dedn       = V('atpDeductions');
    const regime     = document.getElementById('atpRegime')?.value || 'new';
    const alreadyPaid= V('atpAlreadyPaid');

    if (!salary && !business && !ltcg && !stcg && !interest) {
      document.getElementById('atpResult').style.display = 'none';
      document.getElementById('atpEmpty').style.display = '';
      return;
    }
    document.getElementById('atpEmpty').style.display = 'none';

    const stdDedn   = regime === 'new' ? 75000 : 50000;
    const slabIncome = Math.max(0, salary + business + interest - stdDedn - (regime==='old'?dedn:0));
    let slabTax      = taxSlab(slabIncome, regime);
    slabTax          = rebate87A(slabTax, slabIncome, regime);

    const ltcgTaxable = Math.max(0, ltcg - 125000);
    const ltcgTax     = ltcgTaxable * 0.125;
    const stcgTax     = stcg * 0.20;
    const totalTax    = slabTax + ltcgTax + stcgTax;
    const cess        = totalTax * 0.04;
    const grossTax    = totalTax + cess;
    const netDue      = Math.max(0, grossTax - tds - alreadyPaid);

    const today = new Date();
    const fy    = today.getMonth()+1 >= 4 ? today.getFullYear() : today.getFullYear()-1;
    const quarters = [
      {q:'Q1',name:'Jun 15',  deadline:new Date(fy,5,15),  cumPct:0.15},
      {q:'Q2',name:'Sep 15',  deadline:new Date(fy,8,15),  cumPct:0.45},
      {q:'Q3',name:'Dec 15',  deadline:new Date(fy,11,15), cumPct:0.75},
      {q:'Q4',name:'Mar 15',  deadline:new Date(fy+1,2,15),cumPct:1.00},
    ];

    const nextQ = quarters.find(q => q.deadline >= today);
    const badge = document.getElementById('atpNextDueBadge');
    if (nextQ && badge) {
      const days = Math.ceil((nextQ.deadline - today)/86400000);
      badge.style.display = '';
      badge.textContent = `Next: ${nextQ.name} — ${days} days`;
      badge.className = 'badge ' + (days<=14?'badge-danger':days<=30?'badge-warning':'badge-info');
    }

    // 234C penalty if net due > 10000
    let penaltyNote = '';
    if (netDue > 10000) {
      const penalty234C = netDue * 0.01 * 3; // approx 3 months
      penaltyNote = `<div style="margin-top:10px;padding:10px 14px;background:rgba(220,38,38,.06);border:1px solid #fca5a5;border-radius:8px;font-size:12px;">
        ⚠️ <strong>Section 234C Interest:</strong> If quarterly advance tax not paid on time, interest @1%/month on shortfall.
        Estimated penalty if no advance tax paid: approx <strong>${INR(penalty234C)}</strong> (3 months @1%).<br>
        <strong>Section 234B:</strong> If &lt;90% paid by Mar 31, additional 1%/month from Apr 1 to assessment date.
      </div>`;
    }

    let qRows = quarters.map((q,i) => {
      const prevPct = i>0?quarters[i-1].cumPct:0;
      const installment = Math.max(0, netDue * (q.cumPct - prevPct));
      const cumDue = netDue * q.cumPct;
      const past = q.deadline < today;
      const isNext = !past && q === nextQ;
      return `<tr style="border-bottom:1px solid var(--border);${isNext?'background:rgba(99,102,241,.05);font-weight:700;':''}${past?'opacity:.6;':''}">
        <td style="padding:9px 12px;">${q.q}${isNext?' ←':''}${past?' ✓':''}</td>
        <td style="padding:9px 12px;">${q.name}</td>
        <td style="padding:9px 12px;text-align:right;">${(q.cumPct*100).toFixed(0)}%</td>
        <td style="padding:9px 12px;text-align:right;font-variant-numeric:tabular-nums;">${INR(installment)}</td>
        <td style="padding:9px 12px;text-align:right;font-variant-numeric:tabular-nums;">${INR(cumDue)}</td>
        <td style="padding:9px 12px;text-align:center;">${past?'<span style="color:#16a34a;">Past</span>':isNext?'<span style="background:#6366f1;color:#fff;padding:2px 10px;border-radius:99px;font-size:11px;">Next Due</span>':'<span style="color:var(--text-muted);">Upcoming</span>'}</td>
      </tr>`;
    }).join('');

    document.getElementById('atpResult').style.display = '';
    document.getElementById('atpResult').innerHTML = `
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px;">
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Slab Tax (income)</div>
          <div style="font-size:16px;font-weight:800;">${INR(slabTax)}</div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">LTCG Tax (12.5%)</div>
          <div style="font-size:16px;font-weight:800;">${INR(ltcgTax)}</div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">STCG Tax (20%)</div>
          <div style="font-size:16px;font-weight:800;">${INR(stcgTax)}</div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Cess (4%)</div>
          <div style="font-size:16px;font-weight:800;">${INR(cess)}</div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Gross Tax Liability</div>
          <div style="font-size:16px;font-weight:800;">${INR(grossTax)}</div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">TDS Credit</div>
          <div style="font-size:16px;font-weight:800;color:#16a34a;">${INR(tds + alreadyPaid)}</div>
        </div>
        <div style="background:${netDue>0?'rgba(220,38,38,.07)':'rgba(22,163,74,.07)'};border:1.5px solid ${netDue>0?'#fca5a5':'#86efac'};border-radius:8px;padding:12px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">${netDue>0?'Advance Tax Due':'No Advance Tax!'}</div>
          <div style="font-size:18px;font-weight:800;color:${netDue>0?'#dc2626':'#16a34a'};">${netDue>0?INR(netDue):'NIL ✅'}</div>
        </div>
      </div>

      ${netDue > 10000 ? `
      <div style="border:1.5px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px;">
        <div style="background:var(--bg-secondary);padding:10px 12px;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);">📅 Quarterly Advance Tax Schedule</div>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <thead><tr style="border-bottom:1px solid var(--border);background:var(--bg-secondary);">
            <th style="padding:8px 12px;text-align:left;font-size:11px;color:var(--text-muted);">Quarter</th>
            <th style="padding:8px 12px;text-align:left;font-size:11px;color:var(--text-muted);">Due Date</th>
            <th style="padding:8px 12px;text-align:right;font-size:11px;color:var(--text-muted);">Cumul %</th>
            <th style="padding:8px 12px;text-align:right;font-size:11px;color:var(--text-muted);">Instalment</th>
            <th style="padding:8px 12px;text-align:right;font-size:11px;color:var(--text-muted);">Cumul Due</th>
            <th style="padding:8px 12px;text-align:center;font-size:11px;color:var(--text-muted);">Status</th>
          </tr></thead>
          <tbody>${qRows}</tbody>
        </table>
      </div>
      ` : '<div style="padding:14px;text-align:center;color:#16a34a;font-weight:700;font-size:13px;">✅ Advance tax not applicable (liability ≤ ₹10,000 or fully covered by TDS)</div>'}

      ${penaltyNote}

      <div style="font-size:12px;padding:10px 14px;background:var(--bg-secondary);border-radius:8px;margin-top:12px;">
        <strong>How to pay Advance Tax:</strong> Go to <a href="https://www.incometax.gov.in" target="_blank" style="color:var(--accent);">incometax.gov.in</a> → e-Pay Tax → Challan 280 → Select "Advance Tax (100)" → Pay online via net banking / UPI.
        Save the BSR code and challan number for ITR filing.
      </div>
    `;
  }

  async function autoFill() {
    try {
      const resp = await fetch('/api/tax/capital_gains.php?action=tax_summary');
      const data = await resp.json();
      if (data.success && data.data) {
        const d = data.data;
        const el = id => document.getElementById(id);
        if (el('atpLTCG'))    el('atpLTCG').value    = d.equity_ltcg || 0;
        if (el('atpSTCG'))    el('atpSTCG').value    = d.equity_stcg || 0;
        calc();
      }
    } catch(e) {}
  }

  return { calc, autoFill };
})();
</script>

<div class="card mb-4" id="cardTaxComp">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
      <h3 class="card-title">🧾 Tax Computation Sheet</h3>
      <small class="text-secondary">CA-ready statement · WealthDash tracked assets only</small>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" class="no-print">
      <select id="tcAY" class="form-select" style="width:130px;">
        <option value="2026-27">AY 2026-27</option>
        <option value="2025-26">AY 2025-26</option>
        <option value="2024-25">AY 2024-25</option>
      </select>
      <button class="btn btn-secondary" onclick="TC.load()">⟳ Load</button>
      <button class="btn btn-primary no-print" onclick="TC.print()">🖨 Print / PDF</button>
    </div>
  </div>

  <div class="card-body">
    <!-- ── Personal Details ── -->
    <div id="tcPersonal" style="display:none;margin-bottom:20px;padding:14px 16px;background:var(--bg-secondary);border-radius:8px;">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;align-items:end;">
        <div>
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Name</div>
          <div id="tcName" style="font-size:14px;font-weight:700;"></div>
        </div>
        <div>
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">PAN</div>
          <input id="tcPAN" class="form-control" style="font-size:13px;max-width:160px;text-transform:uppercase;letter-spacing:2px;" placeholder="ABCDE1234F" maxlength="10">
        </div>
        <div>
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Assessment Year</div>
          <div id="tcAYDisplay" style="font-size:14px;font-weight:700;"></div>
        </div>
        <div>
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Statement Date</div>
          <div id="tcDate" style="font-size:13px;"></div>
        </div>
      </div>
    </div>

    <!-- ── Loading / Empty ── -->
    <div id="tcEmpty" style="text-align:center;padding:48px;color:var(--text-muted);">
      <div style="font-size:32px;margin-bottom:8px;">🧾</div>
      <div style="font-size:14px;font-weight:600;">Select AY and click Load</div>
      <div style="font-size:12px;margin-top:4px;">Pulls data from Schedule 112A, FY Gains &amp; 80C Dashboard</div>
    </div>
    <div id="tcLoading" style="display:none;text-align:center;padding:48px;">
      <div style="font-size:14px;font-weight:600;color:var(--text-muted);">⏳ Fetching tax data…</div>
    </div>

    <!-- ── Main Table ── -->
    <div id="tcTableWrap" style="display:none;">

      <!-- Print header (hidden on screen) -->
      <div class="print-only" style="text-align:center;margin-bottom:18px;border-bottom:2px solid #000;padding-bottom:12px;">
        <div style="font-size:18px;font-weight:800;">TAX COMPUTATION SHEET</div>
        <div style="font-size:13px;margin-top:4px;">Assessment Year: <span id="tcAYPrint"></span> &nbsp;|&nbsp; Generated by WealthDash</div>
        <div style="font-size:12px;margin-top:2px;">Name: <span id="tcNamePrint"></span> &nbsp;|&nbsp; PAN: <span id="tcPANPrint"></span> &nbsp;|&nbsp; Date: <span id="tcDatePrint"></span></div>
      </div>

      <div style="overflow-x:auto;">
        <table id="tblTaxComp" style="width:100%;border-collapse:collapse;">
          <thead>
            <tr style="background:var(--bg-secondary);">
              <th style="text-align:left;padding:10px 12px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Particulars</th>
              <th style="text-align:right;padding:10px 12px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;width:160px;">Amount (₹)</th>
              <th style="text-align:right;padding:10px 12px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;width:160px;">Net (₹)</th>
            </tr>
          </thead>
          <tbody id="tcTbody"></tbody>
        </table>
      </div>

      <!-- TDS + Advance Tax inputs -->
      <div style="margin-top:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;" id="tcInputsWrap">
        <div style="background:var(--bg-secondary);border-radius:8px;padding:14px;">
          <label style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);display:block;margin-bottom:6px;">TDS Deducted (₹)</label>
          <input type="number" id="tcTDS" class="form-control" placeholder="0" min="0" step="1" oninput="TC.recalc()">
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">As per Form 26AS / AIS</div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:8px;padding:14px;">
          <label style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);display:block;margin-bottom:6px;">Advance Tax Paid (₹)</label>
          <input type="number" id="tcAdvTax" class="form-control" placeholder="0" min="0" step="1" oninput="TC.recalc()">
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Total paid across all installments</div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:8px;padding:14px;">
          <label style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);display:block;margin-bottom:6px;">Self-Assessment Tax (₹)</label>
          <input type="number" id="tcSAT" class="form-control" placeholder="0" min="0" step="1" oninput="TC.recalc()">
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Challan 280 payments</div>
        </div>
      </div>

      <!-- Net payable/refund -->
      <div id="tcNetWrap" style="margin-top:14px;padding:16px 20px;border-radius:10px;border:2px solid;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div>
          <div id="tcNetLabel" style="font-size:13px;font-weight:700;"></div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">After TDS + Advance Tax + Self-Assessment Tax</div>
        </div>
        <div id="tcNetAmt" style="font-size:26px;font-weight:900;"></div>
      </div>

      <!-- Disclaimer -->
      <div style="margin-top:16px;padding:10px 14px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.25);border-radius:7px;font-size:11.5px;color:var(--text-secondary);line-height:1.6;">
        ⚠️ <strong>Disclaimer:</strong> This is a computer-generated estimate based on data entered in WealthDash.
        It covers only investment income tracked in this app (MF, stocks, FD). Salary, house property, business income &amp; other sources are <em>not</em> included.
        Tax rates used: LTCG equity 12.5%, STCG equity 20% (post Budget Jul 2024), Cess 4%.
        <strong>Verify all figures with your Chartered Accountant before filing ITR.</strong>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     t134 — 54EC Bond Tracker
     ═══════════════════════════════════════════════════════════ -->
<div class="card mt-4" id="card54EC">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
    <div>
      <h3 class="card-title">🏛️ 54EC Bond Tracker
        <span style="font-size:11px;background:#3b82f6;color:#fff;padding:2px 8px;border-radius:20px;margin-left:8px;">t134</span>
      </h3>
      <p style="font-size:12px;color:var(--text-muted);margin:2px 0 0;">REC · NHAI · PFC · IRFC — Capital Gains Exemption u/s 54EC (₹50L limit per FY)</p>
    </div>
    <button class="btn btn-primary btn-sm" onclick="Bonds54EC.openAdd()">+ Add Bond</button>
  </div>
  <div class="card-body">
    <!-- Info banner -->
    <div style="background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.2);border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:16px;line-height:1.7;">
      <strong>Section 54EC:</strong> Exempts Long-Term Capital Gains (from land/building) if invested in specified bonds within
      <strong>6 months</strong> of transfer. Max exemption: <strong>₹50 lakh per FY</strong>. Lock-in: <strong>5 years</strong>
      (redemption before maturity = LTCG taxable). Interest is <strong>taxable</strong> as per slab rate.
    </div>

    <!-- Summary row -->
    <div id="b54Summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px;"></div>

    <!-- Table -->
    <div style="overflow-x:auto;">
      <table style="width:100%;font-size:12px;border-collapse:collapse;">
        <thead>
          <tr style="background:var(--bg-secondary);">
            <th style="padding:8px 12px;text-align:left;">Issuer / Bonds</th>
            <th style="padding:8px 12px;text-align:right;">Invested</th>
            <th style="padding:8px 12px;text-align:right;">Rate</th>
            <th style="padding:8px 12px;text-align:left;">Investment Date</th>
            <th style="padding:8px 12px;text-align:left;">Maturity</th>
            <th style="padding:8px 12px;text-align:right;">Interest Earned</th>
            <th style="padding:8px 12px;text-align:right;">Maturity Value</th>
            <th style="padding:8px 12px;text-align:right;">LTCG Exempted</th>
            <th style="padding:8px 12px;text-align:left;">Asset Sale Date</th>
            <th style="padding:8px 12px;text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody id="b54Body">
          <tr><td colspan="10" style="text-align:center;padding:40px;"><span class="spinner"></span></td></tr>
        </tbody>
      </table>
    </div>

    <!-- 54EC limit reminder -->
    <div id="b54LimitWarning" style="display:none;margin-top:12px;padding:10px 14px;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.3);border-radius:8px;font-size:12px;color:#dc2626;"></div>
  </div>
</div>

<!-- Add/Edit 54EC Modal -->
<div class="modal-overlay" id="modal54EC" style="display:none;">
  <div class="modal" style="max-width:580px;">
    <div class="modal-header">
      <h3 class="modal-title" id="b54ModalTitle">Add 54EC Bond</h3>
      <button class="modal-close" onclick="hideModal('modal54EC')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="b54Id">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Bond Issuer *</label>
          <select id="b54Issuer" class="form-select" onchange="Bonds54EC.onIssuerChange()">
            <option value="REC">REC Ltd</option>
            <option value="NHAI">NHAI</option>
            <option value="PFC">PFC Ltd</option>
            <option value="IRFC">IRFC</option>
            <option value="OTHER">Other</option>
          </select>
        </div>
        <div class="form-group" id="b54IssuerNameRow" style="display:none;">
          <label class="form-label">Issuer Name</label>
          <input type="text" id="b54IssuerName" class="form-input" placeholder="Enter issuer name">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Investment Date *</label>
          <input type="date" id="b54InvDate" class="form-input" oninput="Bonds54EC.autoSetMaturity()">
        </div>
        <div class="form-group">
          <label class="form-label">Maturity Date *</label>
          <input type="date" id="b54MatDate" class="form-input">
          <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">Auto-set to investment + 5 years</div>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Face Value per Bond (₹)</label>
          <input type="number" id="b54FaceVal" class="form-input" value="10000">
        </div>
        <div class="form-group">
          <label class="form-label">Number of Bonds *</label>
          <input type="number" id="b54NumBonds" class="form-input" placeholder="e.g. 5" min="1">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Interest Rate (% p.a.)</label>
          <input type="number" id="b54Rate" class="form-input" value="5.00" step="0.01">
        </div>
        <div class="form-group">
          <label class="form-label">Interest Type</label>
          <select id="b54Freq" class="form-select">
            <option value="annual">Annual Payout</option>
            <option value="cumulative">Cumulative</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">LTCG Amount Exempted (₹)</label>
          <input type="number" id="b54Ltcg" class="form-input" placeholder="e.g. 2500000">
          <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">Original capital gain claimed exempt</div>
        </div>
        <div class="form-group">
          <label class="form-label">Original Asset Sale Date</label>
          <input type="date" id="b54SaleDate" class="form-input">
          <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">Must invest within 6 months of this date</div>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Folio / Certificate No.</label>
          <input type="text" id="b54Folio" class="form-input" placeholder="Optional">
        </div>
        <div class="form-group">
          <label class="form-label">Notes</label>
          <input type="text" id="b54Notes" class="form-input" placeholder="Optional notes">
        </div>
      </div>
      <div id="b54Error" class="form-error" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modal54EC')">Cancel</button>
      <button class="btn btn-primary" onclick="Bonds54EC.save()">Save Bond</button>
    </div>
  </div>
</div>

<script>
// ── t377: Tax Computation Sheet ─────────────────────────────────────────
const TC = (() => {
  const INR = v => {
    const n = Math.abs(Number(v)||0);
    return '₹' + n.toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0});
  };

  // AY → FY mapping
  const ayToFy = ay => {
    const [s] = ay.split('-');
    const yr = parseInt(s) - 1;
    return `${yr}-${String(yr-1999).padStart(2,'0')}`;
  };

  let _state = null; // last loaded data

  async function load() {
    const ay = document.getElementById('tcAY').value;
    const fy = ayToFy(ay);

    document.getElementById('tcEmpty').style.display   = 'none';
    document.getElementById('tcLoading').style.display = 'block';
    document.getElementById('tcTableWrap').style.display = 'none';
    document.getElementById('tcPersonal').style.display  = 'none';

    try {
      const BASE = window.APP_URL || window.WD?.appUrl || '';
      const [r112, rFY, r80C] = await Promise.all([
        fetch(`${BASE}/api/reports/fy_gains.php?action=schedule_112a&fy=${fy}`).then(r=>r.json()),
        fetch(`${BASE}/api/reports/fy_gains.php?action=fy_gains&fy=${fy}`).then(r=>r.json()),
        fetch(`${BASE}/api/reports/tax_planning.php?action=calc_80c_dashboard&fy=${fy}`).then(r=>r.json()),
      ]);

      document.getElementById('tcLoading').style.display = 'none';

      const s112 = (r112.success && r112.summary) ? r112.summary : {};
      const sFY  = (rFY.success  && rFY.summary)  ? rFY.summary  : {};
      const s80C = (r80C.success) ? r80C : {};

      // LTCG equity (post-Budget 2024: 12.5%, exempt ₹1.25L)
      const ltcgGross    = Number(s112.ltcg_total    || sFY.ltcg_total    || 0);
      const ltcgExempt   = Math.min(ltcgGross, 125000);
      const ltcgTaxable  = Math.max(0, ltcgGross - ltcgExempt);
      const ltcgTax      = ltcgTaxable * 0.125;

      // STCG equity (20%)
      const stcgGross    = Number(s112.stcg_total    || sFY.stcg_total    || 0);
      const stcgTax      = Math.max(0, stcgGross) * 0.20;

      // Debt fund gains (if sFY has debt gains — flat slab rate, shown as note)
      const debtGain     = Number(sFY.debt_gain || 0);

      // 80C deductions
      const dedn80C      = Math.min(Number(s80C.total_80c || s80C.elss_amount || 0), 150000);
      const dedn80CCD    = Math.min(Number(s80C.nps_80ccd1b || 0), 50000);
      const totalDedn    = dedn80C + dedn80CCD;

      // Gross investment income
      const grossIncome  = ltcgGross + stcgGross + debtGain;

      // Tax before cess
      const taxBeforeCess = ltcgTax + stcgTax;
      // Cess 4% on tax (not on income)
      const cess          = taxBeforeCess * 0.04;
      const totalTaxLiab  = taxBeforeCess + cess;

      _state = { ltcgGross, ltcgExempt, ltcgTaxable, ltcgTax, stcgGross, stcgTax, debtGain, dedn80C, dedn80CCD, totalDedn, grossIncome, taxBeforeCess, cess, totalTaxLiab, ay, fy };

      // Personal details
      const userName = window.WD?.userName || '<?= htmlspecialchars($currentUser['name'] ?? '') ?>';
      document.getElementById('tcName').textContent    = userName || '—';
      document.getElementById('tcNamePrint').textContent = userName || '—';
      document.getElementById('tcAYDisplay').textContent = `AY ${ay}`;
      document.getElementById('tcAYPrint').textContent   = `AY ${ay}`;
      const today = new Date().toLocaleDateString('en-IN', {day:'2-digit',month:'long',year:'numeric'});
      document.getElementById('tcDate').textContent    = today;
      document.getElementById('tcDatePrint').textContent = today;

      document.getElementById('tcPersonal').style.display  = '';
      document.getElementById('tcTableWrap').style.display = '';

      renderTable();
      recalc();

    } catch(e) {
      document.getElementById('tcLoading').style.display = 'none';
      document.getElementById('tcEmpty').style.display   = '';
      document.getElementById('tcEmpty').innerHTML = `<div style="color:#dc2626;font-size:13px;">Error: ${e.message}</div>`;
    }
  }

  function row(label, amt, net, cls = '') {
    const amtCell = amt !== null ? `<td class="amt">${amt !== '' ? INR(amt) : ''}</td>` : '<td></td>';
    const netCell = net !== null ? `<td class="amt" style="font-weight:600;">${net !== '' ? INR(net) : ''}</td>` : '<td></td>';
    return `<tr class="${cls}"><td class="${cls.includes('indent') ? 'indent' : ''}">${label}</td>${amtCell}${netCell}</tr>`;
  }
  function sectionRow(label) {
    return `<tr class="row-section"><td colspan="3" style="padding:10px 12px;font-size:11px;">${label}</td></tr>`;
  }
  function blankRow() { return `<tr><td colspan="3" style="padding:4px;border:none;"></td></tr>`; }

  function renderTable() {
    if (!_state) return;
    const s = _state;
    let html = '';

    // A — Capital Gains Income
    html += sectionRow('A. INCOME FROM CAPITAL GAINS (Investment Assets)');
    html += row('Long-Term Capital Gains — Equity MF / Stocks (LTCG)',      s.ltcgGross, '', 'indent');
    html += row('Less: LTCG Exemption u/s 112A (₹1,25,000)',               s.ltcgExempt !== 0 ? -s.ltcgExempt : 0, '', 'indent');
    html += row('<strong>Taxable LTCG</strong>',                             '', s.ltcgTaxable, 'indent row-total');
    html += row('Short-Term Capital Gains — Equity MF / Stocks (STCG)',    s.stcgGross,  '', 'indent');
    html += row('<strong>Taxable STCG</strong>',                             '', Math.max(0,s.stcgGross), 'indent row-total');
    if (s.debtGain) {
      html += row('Debt Fund Gains (taxed at slab rate)',                   s.debtGain, '', 'indent');
    }
    html += row('<strong>Gross Total Income (from investments)</strong>',    '', s.grossIncome, 'row-total');
    html += blankRow();

    // B — Deductions
    html += sectionRow('B. DEDUCTIONS');
    html += row('80C — ELSS / PPF / LIC / EPF (max ₹1,50,000)',           s.dedn80C,   '', 'indent');
    if (s.dedn80CCD) {
      html += row('80CCD(1B) — NPS (additional, max ₹50,000)',             s.dedn80CCD, '', 'indent');
    }
    html += row('<strong>Total Deductions</strong>',                         '', s.totalDedn, 'row-total');
    html += blankRow();

    // C — Tax Computation
    html += sectionRow('C. TAX COMPUTATION');
    html += row('Tax on LTCG @ 12.5% (post Budget Jul 2024, u/s 112A)',   s.ltcgTax,  '', 'indent');
    html += row('Tax on STCG @ 20% (post Budget Jul 2024, u/s 111A)',     s.stcgTax,  '', 'indent');
    html += row('<strong>Total Tax Before Cess</strong>',                   '', s.taxBeforeCess, 'row-total');
    html += row('Health &amp; Education Cess @ 4%',                        s.cess,     '', 'indent');
    html += row('<strong>Gross Tax Liability</strong>',                     '', s.totalTaxLiab, 'row-total');
    html += blankRow();

    // D — Tax Credits (dynamic, rendered in recalc)
    html += sectionRow('D. TAX CREDITS &amp; PAYMENTS');
    html += `<tr id="tcRowTDS"><td class="indent">TDS Deducted (Form 26AS / AIS)</td><td class="amt" id="tcTDSAmt">₹0</td><td></td></tr>`;
    html += `<tr id="tcRowAdv"><td class="indent">Advance Tax Paid</td><td class="amt" id="tcAdvAmt">₹0</td><td></td></tr>`;
    html += `<tr id="tcRowSAT"><td class="indent">Self-Assessment Tax Paid (Challan 280)</td><td class="amt" id="tcSATAmt">₹0</td><td></td></tr>`;
    html += `<tr class="row-total"><td><strong>Total Credits</strong></td><td></td><td class="amt" id="tcCreditsNet">₹0</td></tr>`;

    document.getElementById('tcTbody').innerHTML = html;
  }

  function recalc() {
    if (!_state) return;
    const tds    = Number(document.getElementById('tcTDS')?.value)    || 0;
    const adv    = Number(document.getElementById('tcAdvTax')?.value) || 0;
    const sat    = Number(document.getElementById('tcSAT')?.value)    || 0;
    const totalCredits = tds + adv + sat;
    const net    = _state.totalTaxLiab - totalCredits;

    const el = id => document.getElementById(id);
    if (el('tcTDSAmt'))     el('tcTDSAmt').textContent     = INR(tds);
    if (el('tcAdvAmt'))     el('tcAdvAmt').textContent     = INR(adv);
    if (el('tcSATAmt'))     el('tcSATAmt').textContent     = INR(sat);
    if (el('tcCreditsNet')) el('tcCreditsNet').textContent = INR(totalCredits);

    const wrap = el('tcNetWrap');
    if (!wrap) return;
    if (net > 0) {
      wrap.style.borderColor    = '#dc2626';
      wrap.style.background     = 'rgba(220,38,38,.05)';
      el('tcNetLabel').textContent = '⚠️ Tax Payable';
      el('tcNetLabel').style.color = '#dc2626';
      el('tcNetAmt').textContent   = INR(net);
      el('tcNetAmt').style.color   = '#dc2626';
    } else {
      wrap.style.borderColor    = '#10b981';
      wrap.style.background     = 'rgba(16,185,129,.05)';
      el('tcNetLabel').textContent = '✅ Refund Due';
      el('tcNetLabel').style.color = '#10b981';
      el('tcNetAmt').textContent   = INR(Math.abs(net));
      el('tcNetAmt').style.color   = '#10b981';
    }

    // Update PAN in print header
    const pan = document.getElementById('tcPAN')?.value || '—';
    const panPrint = document.getElementById('tcPANPrint');
    if (panPrint) panPrint.textContent = pan || '—';
  }

  function print() {
    recalc();
    window.print();
  }

  // Auto-select current AY on load
  (() => {
    const now  = new Date();
    const curAY = now.getMonth() >= 3
      ? `${now.getFullYear()+1}-${String(now.getFullYear()-1998).padStart(2,'0')}`
      : `${now.getFullYear()}-${String(now.getFullYear()-1999).padStart(2,'0')}`;
    const sel = document.getElementById('tcAY');
    if (sel) for (let i=0;i<sel.options.length;i++) if (sel.options[i].value===curAY) { sel.selectedIndex=i; break; }
  })();

  return { load, recalc, print };
})();

/* ═══════════════════════════════════════════════════════════
   t134 — 54EC Bond Tracker
   ═══════════════════════════════════════════════════════════ */
const Bonds54EC = (() => {
  let _bonds = [];

  const ISSUERS = {
    REC:   { label:'REC Ltd',              rate: 5.00, color:'#3b82f6' },
    NHAI:  { label:'NHAI',                 rate: 5.00, color:'#10b981' },
    PFC:   { label:'PFC Ltd',              rate: 5.00, color:'#8b5cf6' },
    IRFC:  { label:'IRFC',                 rate: 5.25, color:'#f59e0b' },
    OTHER: { label:'Other',                rate: 5.00, color:'#6b7280' },
  };

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + Math.round(v).toLocaleString('en-IN');
  }
  function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d);
    return dt.toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'});
  }
  function daysUntil(dateStr) {
    const diff = new Date(dateStr) - new Date();
    return Math.ceil(diff / 86400000);
  }

  async function load() {
    const res  = await fetch(`${APP_URL}/api/router.php?action=bonds54ec_list`);
    const data = await res.json();
    if (!data.success) return;
    _bonds = data.data.bonds || [];
    renderSummary(data.data.summary || {});
    renderTable();
  }

  function renderSummary(s) {
    const el = document.getElementById('b54Summary');
    if (!el) return;
    el.innerHTML = `
      <div style="background:rgba(59,130,246,.08);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:11px;color:var(--text-muted);">Bonds Held</div>
        <div style="font-size:24px;font-weight:800;color:#3b82f6;">${s.count||0}</div>
      </div>
      <div style="background:rgba(99,102,241,.08);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:11px;color:var(--text-muted);">Total Invested</div>
        <div style="font-size:24px;font-weight:800;color:#6366f1;">${fmtI(s.total_invested||0)}</div>
      </div>
      <div style="background:rgba(16,185,129,.08);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:11px;color:var(--text-muted);">LTCG Exempted</div>
        <div style="font-size:24px;font-weight:800;color:#10b981;">${fmtI(s.total_ltcg_saved||0)}</div>
        <div style="font-size:10px;color:var(--text-muted);">u/s 54EC</div>
      </div>
      <div style="background:rgba(245,158,11,.08);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:11px;color:var(--text-muted);">Interest Earned (to date)</div>
        <div style="font-size:24px;font-weight:800;color:#f59e0b;">${fmtI(s.total_interest||0)}</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:11px;color:var(--text-muted);">Total at Maturity</div>
        <div style="font-size:24px;font-weight:800;">${fmtI(s.total_maturity||0)}</div>
      </div>`;
  }

  function renderTable() {
    const tbody = document.getElementById('b54Body');
    if (!tbody) return;
    if (!_bonds.length) {
      tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:48px;color:var(--text-muted);">
        No 54EC bonds added yet. Click <strong>+ Add Bond</strong> to start tracking.
      </td></tr>`;
      return;
    }
    tbody.innerHTML = _bonds.map(b => {
      const c      = b.calc || {};
      const days   = daysUntil(b.maturity_date);
      const iss    = ISSUERS[b.bond_issuer] || ISSUERS.OTHER;
      const name   = b.bond_issuer === 'OTHER' && b.issuer_name ? b.issuer_name : iss.label;
      const badge  = c.is_matured
        ? `<span style="background:#10b981;color:#fff;padding:2px 8px;border-radius:20px;font-size:10px;">Matured</span>`
        : days <= 90
          ? `<span style="background:#dc2626;color:#fff;padding:2px 8px;border-radius:20px;font-size:10px;">Due in ${days}d</span>`
          : days <= 365
            ? `<span style="background:#f59e0b;color:#fff;padding:2px 8px;border-radius:20px;font-size:10px;">${days}d left</span>`
            : `<span style="background:#6366f1;color:#fff;padding:2px 8px;border-radius:20px;font-size:10px;">${Math.round(days/365*10)/10}yr left</span>`;

      const lockNote = c.lock_status
        ? `<div style="font-size:10px;color:#dc2626;margin-top:2px;">🔒 ${c.lock_status}</div>` : '';

      return `<tr>
        <td>
          <div style="font-weight:600;">${escHtml54(name)}</div>
          <div style="font-size:11px;color:var(--text-muted);">${b.num_bonds} bonds × ₹${Number(b.face_value).toLocaleString('en-IN')}</div>
          ${b.folio_number ? `<div style="font-size:10px;color:var(--text-muted);">Folio: ${escHtml54(b.folio_number)}</div>` : ''}
        </td>
        <td style="text-align:right;font-weight:700;">${fmtI(b.total_invested)}</td>
        <td style="text-align:right;color:#f59e0b;font-weight:600;">${b.interest_rate}%</td>
        <td>${fmtDate(b.investment_date)}</td>
        <td>
          ${fmtDate(b.maturity_date)}<br>
          ${badge}
          ${lockNote}
        </td>
        <td style="text-align:right;color:#10b981;">${fmtI(c.interest_earned||0)}</td>
        <td style="text-align:right;font-weight:700;">${fmtI(c.maturity_value||0)}</td>
        <td style="text-align:right;color:#16a34a;font-weight:700;">${fmtI(b.ltcg_exempted||0)}</td>
        <td style="font-size:11px;color:var(--text-muted);">${b.sale_asset_date ? fmtDate(b.sale_asset_date) : '—'}</td>
        <td style="text-align:center;">
          <button class="btn btn-xs btn-ghost" onclick="Bonds54EC.openEdit(${b.id})" title="Edit">✎</button>
          <button class="btn btn-xs btn-ghost" onclick="Bonds54EC.del(${b.id})" title="Delete">✕</button>
        </td>
      </tr>`;
    }).join('');
  }

  function openAdd() {
    // Reset form
    ['b54Issuer','b54InvDate','b54MatDate','b54FaceVal','b54NumBonds',
     'b54Rate','b54Freq','b54Ltcg','b54SaleDate','b54Folio','b54Notes','b54IssuerName'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    document.getElementById('b54Id').value = '';
    document.getElementById('b54Issuer').value = 'REC';
    document.getElementById('b54FaceVal').value = '10000';
    document.getElementById('b54NumBonds').value = '1';
    document.getElementById('b54Rate').value = '5.00';
    document.getElementById('b54Freq').value = 'annual';
    // Auto set maturity = investment + 5yr when investment date changes
    document.getElementById('b54InvDate').value = new Date().toISOString().slice(0,10);
    autoSetMaturity();
    document.getElementById('b54ModalTitle').textContent = 'Add 54EC Bond';
    document.getElementById('b54IssuerNameRow').style.display = 'none';
    showModal('modal54EC');
  }

  function openEdit(id) {
    const b = _bonds.find(x => x.id == id);
    if (!b) return;
    document.getElementById('b54Id').value          = b.id;
    document.getElementById('b54Issuer').value      = b.bond_issuer;
    document.getElementById('b54InvDate').value     = b.investment_date;
    document.getElementById('b54MatDate').value     = b.maturity_date;
    document.getElementById('b54FaceVal').value     = b.face_value;
    document.getElementById('b54NumBonds').value    = b.num_bonds;
    document.getElementById('b54Rate').value        = b.interest_rate;
    document.getElementById('b54Freq').value        = b.interest_freq;
    document.getElementById('b54Ltcg').value        = b.ltcg_exempted;
    document.getElementById('b54SaleDate').value    = b.sale_asset_date || '';
    document.getElementById('b54Folio').value       = b.folio_number || '';
    document.getElementById('b54Notes').value       = b.notes || '';
    document.getElementById('b54IssuerName').value  = b.issuer_name || '';
    document.getElementById('b54IssuerNameRow').style.display = b.bond_issuer === 'OTHER' ? '' : 'none';
    document.getElementById('b54ModalTitle').textContent = 'Edit 54EC Bond';
    showModal('modal54EC');
  }

  async function save() {
    const id = document.getElementById('b54Id').value;
    const payload = {
      action:          id ? 'bonds54ec_edit' : 'bonds54ec_add',
      id:              id || undefined,
      bond_issuer:     document.getElementById('b54Issuer').value,
      issuer_name:     document.getElementById('b54IssuerName').value,
      investment_date: document.getElementById('b54InvDate').value,
      maturity_date:   document.getElementById('b54MatDate').value,
      face_value:      document.getElementById('b54FaceVal').value,
      num_bonds:       document.getElementById('b54NumBonds').value,
      interest_rate:   document.getElementById('b54Rate').value,
      interest_freq:   document.getElementById('b54Freq').value,
      ltcg_exempted:   document.getElementById('b54Ltcg').value || 0,
      sale_asset_date: document.getElementById('b54SaleDate').value || '',
      folio_number:    document.getElementById('b54Folio').value || '',
      notes:           document.getElementById('b54Notes').value || '',
    };
    const res = await apiPost(payload);
    if (res.success) {
      hideModal('modal54EC');
      showToast(id ? 'Bond updated.' : 'Bond added!', 'success');
      load();
    } else {
      document.getElementById('b54Error').textContent = res.message;
      document.getElementById('b54Error').style.display = '';
    }
  }

  async function del(id) {
    if (!confirm('Delete this 54EC bond record?')) return;
    const res = await apiPost({action:'bonds54ec_delete', id});
    if (res.success) { showToast('Deleted.', 'success'); load(); }
  }

  function onIssuerChange() {
    const val = document.getElementById('b54Issuer').value;
    document.getElementById('b54IssuerNameRow').style.display = val === 'OTHER' ? '' : 'none';
    if (ISSUERS[val]) document.getElementById('b54Rate').value = ISSUERS[val].rate.toFixed(2);
  }

  function autoSetMaturity() {
    const inv = document.getElementById('b54InvDate').value;
    if (!inv || document.getElementById('b54MatDate').value) return;
    const d = new Date(inv);
    d.setFullYear(d.getFullYear() + 5);
    document.getElementById('b54MatDate').value = d.toISOString().slice(0,10);
  }

  function escHtml54(t){const d=document.createElement('div');d.appendChild(document.createTextNode(t||''));return d.innerHTML;}

  document.addEventListener('DOMContentLoaded', load);
  return { load, openAdd, openEdit, save, del, onIssuerChange, autoSetMaturity };
})();
</script>
<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
