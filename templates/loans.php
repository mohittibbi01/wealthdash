<?php
/**
 * WealthDash — Loan Tracker (t123)
 * t325: EMI Calculator & Amortization
 * t326: Loan vs Invest Decision Tool
 * t327: Home Loan Tax Benefit Calculator
 * t464: Home Loan EMI Tracker (rate history, prepayments, tax claims, EMI calendar)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Loan Tracker';
$activePage  = 'loans';
$extraScripts = '<script src="' . APP_URL . '/public/js/charts.js?v=' . ASSET_VERSION . '"></script>';

$db = DB::conn();
$pStmt = $db->prepare("SELECT id, name FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">🏦 Loan Tracker</h1>
    <p class="page-subtitle">Home · Personal · Education · Vehicle · EMI schedule</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-outline btn-sm" onclick="HL.open()">🏠 Home Loan Tracker</button>
    <button class="btn btn-primary" onclick="openAddLoan()">+ Add Loan</button>
  </div>
</div>

<div class="stats-grid" style="margin-bottom:20px;">
  <div class="stat-card"><div class="stat-label">Active Loans</div><div class="stat-value" id="loanCount">—</div></div>
  <div class="stat-card"><div class="stat-label">Total Outstanding</div><div class="stat-value text-danger" id="loanOutstanding">—</div></div>
  <div class="stat-card"><div class="stat-label">Monthly EMI Total</div><div class="stat-value" id="loanEmi">—</div></div>
  <div class="stat-card"><div class="stat-label">EMI-to-Income Ratio</div><div class="stat-value" id="loanEmiRatio">—</div></div>
</div>

<!-- EMI-to-income input -->
<div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
  <label style="font-size:12px;color:var(--text-muted);">Monthly Income (₹) for ratio:</label>
  <input type="number" id="monthlyIncome" placeholder="e.g. 100000" class="form-input" style="width:150px;" oninput="updateEmiRatio()">
  <select id="loanFilterPortfolio" class="form-select" style="width:170px;" onchange="loadLoans()">
    <option value="">All Portfolios</option>
    <?php foreach ($portfolios as $p): ?>
    <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Lender / Type</th>
          <th class="text-right">Principal</th>
          <th class="text-right">Outstanding</th>
          <th class="text-right">Rate</th>
          <th class="text-right">EMI</th>
          <th>EMI Date</th>
          <th class="text-right">Monthly Interest</th>
          <th>Tax Benefit</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="loanBody">
        <tr><td colspan="9" class="text-center" style="padding:40px;"><span class="spinner"></span></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ─── t325: Prepayment Calculator ─── -->
<div class="card mt-4">
  <div class="card-header">
    <h3 class="card-title">⚡ Prepayment Calculator <span style="font-size:11px;background:#6366f1;color:#fff;padding:2px 8px;border-radius:20px;margin-left:8px;">t325</span></h3>
    <span style="font-size:12px;color:var(--text-muted);">How many EMIs saved by making a lump-sum prepayment?</span>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:16px;">
      <div class="form-group">
        <label class="form-label">Outstanding Balance (₹) *</label>
        <input type="number" id="ppOutstanding" class="form-input" placeholder="e.g. 4500000" oninput="calcPrepayment()">
      </div>
      <div class="form-group">
        <label class="form-label">Interest Rate (% p.a.) *</label>
        <input type="number" id="ppRate" class="form-input" placeholder="e.g. 8.5" step="0.01" oninput="calcPrepayment()">
      </div>
      <div class="form-group">
        <label class="form-label">Current EMI (₹) *</label>
        <input type="number" id="ppEmi" class="form-input" placeholder="e.g. 38000" oninput="calcPrepayment()">
      </div>
      <div class="form-group">
        <label class="form-label">Prepayment Amount (₹) *</label>
        <input type="number" id="ppAmount" class="form-input" placeholder="e.g. 200000" oninput="calcPrepayment()">
      </div>
      <div class="form-group">
        <label class="form-label">After Prepayment — Choose:</label>
        <select id="ppStrategy" class="form-select" onchange="calcPrepayment()">
          <option value="reduce_tenure">Reduce Tenure (keep same EMI)</option>
          <option value="reduce_emi">Reduce EMI (keep same tenure)</option>
        </select>
      </div>
    </div>
    <div id="ppResult" style="display:none;background:var(--bg-secondary);border-radius:10px;padding:20px;">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:16px;">
        <div style="text-align:center;">
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">EMIs Saved</div>
          <div id="ppEmisSaved" style="font-size:28px;font-weight:700;color:#16a34a;">—</div>
        </div>
        <div style="text-align:center;">
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Interest Saved</div>
          <div id="ppIntSaved" style="font-size:28px;font-weight:700;color:#16a34a;">—</div>
        </div>
        <div style="text-align:center;">
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">New Tenure / EMI</div>
          <div id="ppNewTenure" style="font-size:22px;font-weight:700;color:#6366f1;">—</div>
        </div>
        <div style="text-align:center;">
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;">Effective Return on Prepayment</div>
          <div id="ppReturnPct" style="font-size:22px;font-weight:700;color:#f59e0b;">—</div>
          <div style="font-size:10px;color:var(--text-muted);">(= loan interest rate)</div>
        </div>
      </div>
      <div id="ppComparision" style="font-size:12px;color:var(--text-muted);border-top:1px solid var(--border-color);padding-top:12px;"></div>
    </div>
    <!-- Prepayment amortization comparison -->
    <div id="ppChartWrap" style="display:none;margin-top:16px;">
      <div style="font-size:13px;font-weight:600;margin-bottom:10px;">Balance Comparison: With vs Without Prepayment</div>
      <canvas id="ppChart" height="140"></canvas>
    </div>
  </div>
</div>

<!-- ─── t326: Loan vs Invest Decision Tool ─── -->
<div class="card mt-4">
  <div class="card-header">
    <h3 class="card-title">⚖️ Loan vs Invest Decision Tool <span style="font-size:11px;background:#10b981;color:#fff;padding:2px 8px;border-radius:20px;margin-left:8px;">t326</span></h3>
    <span style="font-size:12px;color:var(--text-muted);">Should I prepay my loan or invest the surplus?</span>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:20px;">
      <div class="form-group">
        <label class="form-label">Loan Interest Rate (% p.a.) *</label>
        <input type="number" id="lviLoanRate" class="form-input" value="8.5" step="0.01" oninput="calcLoanVsInvest()">
      </div>
      <div class="form-group">
        <label class="form-label">Expected Investment Return (% p.a.) *</label>
        <input type="number" id="lviInvReturn" class="form-input" value="12" step="0.01" oninput="calcLoanVsInvest()">
      </div>
      <div class="form-group">
        <label class="form-label">Surplus Amount (₹) *</label>
        <input type="number" id="lviSurplus" class="form-input" placeholder="e.g. 200000" oninput="calcLoanVsInvest()">
      </div>
      <div class="form-group">
        <label class="form-label">Loan Outstanding (₹) *</label>
        <input type="number" id="lviOutstanding" class="form-input" placeholder="e.g. 4500000" oninput="calcLoanVsInvest()">
      </div>
      <div class="form-group">
        <label class="form-label">Remaining Tenure (years)</label>
        <input type="number" id="lviTenure" class="form-input" value="10" step="0.5" oninput="calcLoanVsInvest()">
      </div>
      <div class="form-group">
        <label class="form-label">Tax Bracket</label>
        <select id="lviTaxBracket" class="form-select" onchange="calcLoanVsInvest()">
          <option value="0">0% (No tax)</option>
          <option value="10">10%</option>
          <option value="20">20%</option>
          <option value="30" selected>30%</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Loan Type (Tax Benefit?)</label>
        <select id="lviLoanType" class="form-select" onchange="calcLoanVsInvest()">
          <option value="none">None</option>
          <option value="home">Home Loan (24b + 80C)</option>
          <option value="education">Education Loan (80E)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Investment Type</label>
        <select id="lviInvType" class="form-select" onchange="calcLoanVsInvest()">
          <option value="equity">Equity MF (LTCG 12.5%)</option>
          <option value="debt">Debt MF / FD (30% slab)</option>
          <option value="elss">ELSS (80C + LTCG 12.5%)</option>
        </select>
      </div>
    </div>

    <div id="lviResult" style="display:none;">
      <!-- Verdict Banner -->
      <div id="lviVerdict" style="border-radius:12px;padding:20px;margin-bottom:20px;text-align:center;"></div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
        <div style="background:var(--bg-secondary);border-radius:10px;padding:16px;">
          <div style="font-size:13px;font-weight:700;margin-bottom:12px;color:#dc2626;">💰 Prepay Scenario</div>
          <div id="lviPrepayDetails"></div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:10px;padding:16px;">
          <div style="font-size:13px;font-weight:700;margin-bottom:12px;color:#16a34a;">📈 Invest Scenario</div>
          <div id="lviInvestDetails"></div>
        </div>
      </div>

      <div style="margin-bottom:16px;">
        <div style="font-size:13px;font-weight:600;margin-bottom:10px;">Wealth Comparison Over Time</div>
        <canvas id="lviChart" height="160"></canvas>
      </div>

      <div id="lviFactors" style="background:var(--bg-secondary);border-radius:10px;padding:16px;font-size:12px;"></div>
    </div>
  </div>
</div>

<!-- ─── t327: Home Loan Tax Benefit Calculator ─── -->
<div class="card mt-4">
  <div class="card-header">
    <h3 class="card-title">🏠 Home Loan Tax Benefit Calculator <span style="font-size:11px;background:#f59e0b;color:#fff;padding:2px 8px;border-radius:20px;margin-left:8px;">t327</span></h3>
    <span style="font-size:12px;color:var(--text-muted);">Section 24(b) interest + 80C principal + 80EE/80EEA first-home bonus</span>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:20px;">
      <div class="form-group">
        <label class="form-label">Annual Loan Interest Paid (₹) *</label>
        <input type="number" id="hltInterest" class="form-input" placeholder="e.g. 340000" oninput="calcHomeLoanTax()">
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">From your lender's annual statement</div>
      </div>
      <div class="form-group">
        <label class="form-label">Annual Principal Repaid (₹) *</label>
        <input type="number" id="hltPrincipal" class="form-input" placeholder="e.g. 120000" oninput="calcHomeLoanTax()">
      </div>
      <div class="form-group">
        <label class="form-label">Your Tax Bracket</label>
        <select id="hltTaxBracket" class="form-select" onchange="calcHomeLoanTax()">
          <option value="5">5%</option>
          <option value="20">20%</option>
          <option value="30" selected>30%</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Property Type</label>
        <select id="hltPropType" class="form-select" onchange="calcHomeLoanTax()">
          <option value="self">Self-Occupied</option>
          <option value="let">Let-Out / Rented</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">First Home Buyer?</label>
        <select id="hltFirstHome" class="form-select" onchange="calcHomeLoanTax()">
          <option value="no">No</option>
          <option value="80ee">Yes — 80EE (loan before Apr 2022, value ≤45L)</option>
          <option value="80eea">Yes — 80EEA (affordable housing ≤45L)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Other 80C Investments Already (₹)</label>
        <input type="number" id="hltOther80C" class="form-input" placeholder="e.g. 50000" value="0" oninput="calcHomeLoanTax()">
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">PF, PPF, ELSS, LIC etc.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Co-borrower? (Joint loan)</label>
        <select id="hltJoint" class="form-select" onchange="calcHomeLoanTax()">
          <option value="no">No</option>
          <option value="yes">Yes — both claim separately</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Annual Rent Received (₹) <small style="color:var(--text-muted);">(let-out only)</small></label>
        <input type="number" id="hltRent" class="form-input" placeholder="0" value="0" oninput="calcHomeLoanTax()">
      </div>
    </div>

    <div id="hltResult" style="display:none;">
      <!-- Summary Cards -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;">
        <div style="background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.25);border-radius:10px;padding:14px;text-align:center;">
          <div style="font-size:11px;color:var(--text-muted);">24(b) Interest Deduction</div>
          <div id="hlt24b" style="font-size:22px;font-weight:700;color:#6366f1;margin:4px 0;">—</div>
          <div id="hlt24bNote" style="font-size:10px;color:var(--text-muted);"></div>
        </div>
        <div style="background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.25);border-radius:10px;padding:14px;text-align:center;">
          <div style="font-size:11px;color:var(--text-muted);">80C Principal Deduction</div>
          <div id="hlt80c" style="font-size:22px;font-weight:700;color:#10b981;margin:4px 0;">—</div>
          <div id="hlt80cNote" style="font-size:10px;color:var(--text-muted);"></div>
        </div>
        <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:14px;text-align:center;">
          <div style="font-size:11px;color:var(--text-muted);">80EE / 80EEA Bonus</div>
          <div id="hlt80ee" style="font-size:22px;font-weight:700;color:#f59e0b;margin:4px 0;">—</div>
          <div id="hlt80eeNote" style="font-size:10px;color:var(--text-muted);"></div>
        </div>
        <div style="background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.25);border-radius:10px;padding:14px;text-align:center;">
          <div style="font-size:11px;color:var(--text-muted);">Total Tax Saved (₹)</div>
          <div id="hltTaxSaved" style="font-size:22px;font-weight:700;color:#dc2626;margin:4px 0;">—</div>
          <div id="hltTaxSavedNote" style="font-size:10px;color:var(--text-muted);"></div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;text-align:center;">
          <div style="font-size:11px;color:var(--text-muted);">Effective Loan Cost (post-tax)</div>
          <div id="hltEffRate" style="font-size:22px;font-weight:700;margin:4px 0;">—</div>
        </div>
      </div>

      <!-- Breakdown table -->
      <div style="overflow-x:auto;margin-bottom:16px;">
        <table style="width:100%;font-size:12px;border-collapse:collapse;">
          <thead>
            <tr style="background:var(--bg-secondary);">
              <th style="padding:8px 12px;text-align:left;">Section</th>
              <th style="padding:8px 12px;text-align:left;">Description</th>
              <th style="padding:8px 12px;text-align:right;">Amount Paid</th>
              <th style="padding:8px 12px;text-align:right;">Deduction Claimed</th>
              <th style="padding:8px 12px;text-align:right;">Tax Saving</th>
            </tr>
          </thead>
          <tbody id="hltBreakdownBody"></tbody>
        </table>
      </div>

      <!-- Let-out HP computation if applicable -->
      <div id="hltHPSection" style="display:none;background:var(--bg-secondary);border-radius:10px;padding:16px;margin-bottom:16px;">
        <div style="font-size:13px;font-weight:700;margin-bottom:10px;">🏘️ House Property Income Computation (Let-Out)</div>
        <div id="hltHPBody" style="font-size:12px;line-height:2;"></div>
      </div>

      <!-- Joint loan note -->
      <div id="hltJointNote" style="display:none;background:rgba(99,102,241,.06);border-left:3px solid #6366f1;padding:10px 14px;border-radius:6px;font-size:12px;margin-bottom:12px;"></div>

      <!-- ITR schedule note -->
      <div id="hltITRNote" style="background:rgba(16,185,129,.06);border-left:3px solid #10b981;padding:10px 14px;border-radius:6px;font-size:12px;"></div>
    </div>
  </div>
</div>

<!-- Amortization section -->
<div class="card mt-4" id="amortCard" style="display:none;">
  <div class="card-header">
    <h3 class="card-title" id="amortTitle">Amortization Schedule</h3>
    <button class="btn btn-ghost btn-sm" onclick="document.getElementById('amortCard').style.display='none'">✕</button>
  </div>
  <div class="card-body" id="amortBody" style="overflow-x:auto;max-height:400px;overflow-y:auto;"></div>
</div>

<!-- Add Loan Modal -->
<div class="modal-overlay" id="modalAddLoan" style="display:none;">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">
      <h3 class="modal-title">Add Loan</h3>
      <button class="modal-close" onclick="hideModal('modalAddLoan')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Portfolio *</label>
          <select id="loanPPortfolio" class="form-select">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Loan Type *</label>
          <select id="loanPType" class="form-select">
            <option value="home">Home Loan</option>
            <option value="personal">Personal Loan</option>
            <option value="education">Education Loan</option>
            <option value="vehicle">Vehicle Loan</option>
            <option value="gold">Gold Loan</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Lender *</label>
          <input type="text" id="loanPLender" class="form-input" placeholder="SBI, HDFC, etc.">
        </div>
        <div class="form-group">
          <label class="form-label">Loan Number</label>
          <input type="text" id="loanPNumber" class="form-input" placeholder="Optional">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Principal Amount (₹) *</label>
          <input type="number" id="loanPPrincipal" class="form-input" placeholder="e.g. 5000000">
        </div>
        <div class="form-group">
          <label class="form-label">Outstanding Balance (₹) *</label>
          <input type="number" id="loanPOutstanding" class="form-input" placeholder="Current balance">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Interest Rate (% p.a.) *</label>
          <input type="number" id="loanPRate" class="form-input" placeholder="e.g. 8.5" step="0.01">
        </div>
        <div class="form-group">
          <label class="form-label">EMI Amount (₹) *</label>
          <input type="number" id="loanPEmi" class="form-input" placeholder="Monthly EMI" oninput="calcLoanEmi()">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">EMI Date (day of month)</label>
          <input type="number" id="loanPEmiDate" class="form-input" value="5" min="1" max="31">
        </div>
        <div class="form-group">
          <label class="form-label">Start Date *</label>
          <input type="date" id="loanPStart" class="form-input" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Tenure (months) *</label>
        <input type="number" id="loanPTenure" class="form-input" placeholder="e.g. 240 for 20yr">
      </div>
      <div id="loanCalcPreview" style="padding:8px;background:var(--bg-secondary);border-radius:7px;font-size:12px;color:var(--text-muted);margin-top:8px;"></div>
      <div id="loanError" class="form-error" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modalAddLoan')">Cancel</button>
      <button class="btn btn-primary" onclick="saveLoan()">Save Loan</button>
    </div>
  </div>
</div>

<script>
let _loanData = [];
const LOAN_ICONS = {home:'🏠',personal:'💳',education:'🎓',vehicle:'🚗',gold:'🥇',other:'💰'};
const LOAN_LABELS= {home:'Home',personal:'Personal',education:'Education',vehicle:'Vehicle',gold:'Gold',other:'Other'};

async function loadLoans() {
  const pid = document.getElementById('loanFilterPortfolio')?.value || '';
  const res = await fetch(`${APP_URL}/api/router.php?action=loans_list&portfolio_id=${pid}`);
  const d   = await res.json();
  if (!d.success) return;
  _loanData = d.data || [];
  renderLoanTable();
  updateLoanSummary();
}

function updateLoanSummary() {
  const total       = _loanData.length;
  const outstanding = _loanData.reduce((s,l) => s+(parseFloat(l.outstanding)||0), 0);
  const emi         = _loanData.reduce((s,l) => s+(parseFloat(l.emi_amount)||0), 0);
  function fmtI(v){v=Math.abs(v);if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr';if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}
  document.getElementById('loanCount').textContent      = total;
  document.getElementById('loanOutstanding').textContent= fmtI(outstanding);
  document.getElementById('loanEmi').textContent        = fmtI(emi) + '/mo';
  window._totalEmi = emi;
  updateEmiRatio();
}

function updateEmiRatio() {
  const income = parseFloat(document.getElementById('monthlyIncome')?.value) || 0;
  const emi    = window._totalEmi || 0;
  const el     = document.getElementById('loanEmiRatio');
  if (!income || !emi) { el.textContent = '—'; return; }
  const ratio  = (emi/income*100).toFixed(1);
  el.textContent = ratio + '%';
  el.style.color = ratio > 50 ? '#dc2626' : ratio > 40 ? '#d97706' : '#16a34a';
}

function renderLoanTable() {
  const body = document.getElementById('loanBody');
  if (!_loanData.length) {
    body.innerHTML = '<tr><td colspan="9" class="text-center" style="padding:48px;color:var(--text-muted);">No loans found. Add your first loan.</td></tr>';
    return;
  }
  function fmtI(v){v=Math.abs(v);if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr';if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}
  body.innerHTML = _loanData.map(l => {
    const taxBenefit = l.loan_type === 'home'
      ? '<span title="80C: principal, 24(b): interest ₹2L" style="font-size:11px;color:#16a34a;cursor:help;">80C+24(b) ✅</span>'
      : l.loan_type === 'education' ? '<span style="font-size:11px;color:#3b82f6;">80E ✅</span>'
      : '<span style="color:var(--text-muted);font-size:11px;">—</span>';
    return `<tr>
      <td>
        <div class="fund-name">${LOAN_ICONS[l.loan_type]||'💰'} ${escHtml(l.lender)}</div>
        <div class="fund-sub">${LOAN_LABELS[l.loan_type]||l.loan_type}${l.loan_number?' · #'+escHtml(l.loan_number):''}</div>
      </td>
      <td class="text-right">${fmtI(l.principal)}</td>
      <td class="text-right fw-600 text-danger">${fmtI(l.outstanding)}</td>
      <td class="text-right">${parseFloat(l.interest_rate).toFixed(2)}%</td>
      <td class="text-right fw-600">${fmtI(l.emi_amount)}/mo</td>
      <td>${l.emi_date}<sup>th</sup></td>
      <td class="text-right text-danger">${fmtI(l.monthly_interest)}</td>
      <td>${taxBenefit}</td>
      <td class="text-center" style="display:flex;gap:4px;justify-content:center;">
        <button class="btn btn-xs btn-ghost" onclick="showAmortization(${JSON.stringify(l).replace(/"/g,'&quot;')})" title="Amortization">📋</button>
        <button class="btn btn-xs btn-ghost" onclick="updateLoanBalance(${l.id},${l.outstanding})" title="Update balance">✎</button>
        <button class="btn btn-xs btn-ghost" onclick="deleteLoan(${l.id})" title="Delete">✕</button>
      </td>
    </tr>`;
  }).join('');
}

function showAmortization(loan) {
  const card  = document.getElementById('amortCard');
  const title = document.getElementById('amortTitle');
  const body  = document.getElementById('amortBody');
  title.textContent = `Amortization — ${loan.lender}`;
  card.style.display = '';

  const P   = parseFloat(loan.outstanding);
  const r   = parseFloat(loan.interest_rate) / 100 / 12;
  const emi = parseFloat(loan.emi_amount);
  const n   = Math.ceil(P > 0 && emi > 0 ? Math.log(emi/(emi - P*r)) / Math.log(1+r) : 0);

  let rows = '', bal = P, totalInt = 0;
  function fmtI(v){return'₹'+Math.abs(v).toLocaleString('en-IN',{maximumFractionDigits:0});}
  for (let m = 1; m <= Math.min(n, 360); m++) {
    const int  = bal * r;
    const prin = emi - int;
    bal -= prin;
    totalInt += int;
    if (m <= 12 || m % 12 === 0 || m === Math.round(n)) {
      rows += `<tr style="${m % 12 === 0 ? 'background:rgba(99,102,241,.05);font-weight:700;' : ''}">
        <td style="padding:4px 8px;">${m}</td>
        <td style="padding:4px 8px;text-align:right;">${fmtI(emi)}</td>
        <td style="padding:4px 8px;text-align:right;">${fmtI(prin)}</td>
        <td style="padding:4px 8px;text-align:right;color:#dc2626;">${fmtI(int)}</td>
        <td style="padding:4px 8px;text-align:right;">${fmtI(Math.max(0,bal))}</td>
      </tr>`;
    }
  }
  body.innerHTML = `
    <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">
      Total Interest: <strong style="color:#dc2626;">${fmtI(totalInt)}</strong> · Months remaining: <strong>${Math.round(n)}</strong>
    </div>
    <table style="width:100%;font-size:12px;border-collapse:collapse;">
      <thead style="background:var(--bg-secondary);">
        <tr>
          <th style="padding:6px 8px;text-align:left;">Month</th>
          <th style="padding:6px 8px;text-align:right;">EMI</th>
          <th style="padding:6px 8px;text-align:right;">Principal</th>
          <th style="padding:6px 8px;text-align:right;">Interest</th>
          <th style="padding:6px 8px;text-align:right;">Balance</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>`;
  card.scrollIntoView({behavior:'smooth'});
}

function calcLoanEmi() {
  const P   = parseFloat(document.getElementById('loanPOutstanding')?.value || document.getElementById('loanPPrincipal')?.value) || 0;
  const r   = (parseFloat(document.getElementById('loanPRate')?.value) || 0) / 100 / 12;
  const n   = parseInt(document.getElementById('loanPTenure')?.value) || 0;
  const el  = document.getElementById('loanCalcPreview');
  if (!P || !r || !n) return;
  const emi = P * r * Math.pow(1+r,n) / (Math.pow(1+r,n)-1);
  const total = emi * n;
  el.innerHTML = `Calculated EMI: <strong>₹${Math.round(emi).toLocaleString('en-IN')}/mo</strong> · Total payable: <strong>₹${Math.round(total).toLocaleString('en-IN')}</strong> · Interest: ₹${Math.round(total-P).toLocaleString('en-IN')}`;
  document.getElementById('loanPEmi').value = Math.round(emi);
}

function openAddLoan() { showModal('modalAddLoan'); }
async function saveLoan() {
  const payload = {action:'loans_add',
    portfolio_id: document.getElementById('loanPPortfolio').value,
    loan_type:    document.getElementById('loanPType').value,
    lender:       document.getElementById('loanPLender').value,
    loan_number:  document.getElementById('loanPNumber').value,
    principal:    document.getElementById('loanPPrincipal').value,
    outstanding:  document.getElementById('loanPOutstanding').value,
    interest_rate:document.getElementById('loanPRate').value,
    emi_amount:   document.getElementById('loanPEmi').value,
    emi_date:     document.getElementById('loanPEmiDate').value,
    start_date:   document.getElementById('loanPStart').value,
    tenure_months:document.getElementById('loanPTenure').value,
  };
  if (!payload.lender || !payload.principal || !payload.emi_amount) {
    document.getElementById('loanError').textContent = 'Lender, principal and EMI are required.';
    document.getElementById('loanError').style.display = ''; return;
  }
  const res = await apiPost(payload);
  if (res.success) { hideModal('modalAddLoan'); showToast('Loan added!','success'); loadLoans(); }
  else { document.getElementById('loanError').textContent = res.message; document.getElementById('loanError').style.display = ''; }
}
async function updateLoanBalance(id, current) {
  const val = prompt('Enter current outstanding balance (₹):', current);
  if (!val) return;
  await apiPost({action:'loans_update_outstanding', id, outstanding:val});
  showToast('Balance updated.','success'); loadLoans();
}
async function deleteLoan(id) {
  if (!confirm('Delete this loan?')) return;
  await apiPost({action:'loans_delete', id});
  showToast('Loan deleted.','success'); loadLoans();
}
function escHtml(t){const d=document.createElement('div');d.appendChild(document.createTextNode(t||''));return d.innerHTML;}
document.addEventListener('DOMContentLoaded', loadLoans);

/* ─── t325: Prepayment Calculator ─── */
let _ppChart = null;
function calcPrepayment() {
  const P    = parseFloat(document.getElementById('ppOutstanding').value) || 0;
  const rAnn = parseFloat(document.getElementById('ppRate').value) || 0;
  const emi  = parseFloat(document.getElementById('ppEmi').value) || 0;
  const prep = parseFloat(document.getElementById('ppAmount').value) || 0;
  const strat= document.getElementById('ppStrategy').value;

  if (!P || !rAnn || !emi || !prep) {
    document.getElementById('ppResult').style.display = 'none';
    document.getElementById('ppChartWrap').style.display = 'none';
    return;
  }
  const r = rAnn / 100 / 12;
  function monthsLeft(bal, monthlyEmi) {
    if (monthlyEmi <= bal * r) return Infinity;
    return Math.ceil(Math.log(monthlyEmi / (monthlyEmi - bal * r)) / Math.log(1 + r));
  }
  function totalInterest(bal, monthlyEmi) {
    let n = monthsLeft(bal, monthlyEmi);
    if (!isFinite(n)) return Infinity;
    return monthlyEmi * n - bal;
  }

  const origMonths = monthsLeft(P, emi);
  const origInt    = totalInterest(P, emi);

  let newMonths, newEmi, savedInt, savedMonths, newTenureLabel, newEmiLabel;

  if (strat === 'reduce_tenure') {
    // Prepay reduces balance, keep EMI same
    newMonths    = monthsLeft(P - prep, emi);
    newEmi       = emi;
    savedMonths  = origMonths - newMonths;
    savedInt     = totalInterest(P, emi) - totalInterest(P - prep, emi);
    newTenureLabel = isFinite(newMonths) ? `${Math.floor(newMonths/12)}yr ${newMonths%12}mo` : '—';
    newEmiLabel    = `EMI: ₹${Math.round(emi).toLocaleString('en-IN')} (unchanged)`;
  } else {
    // Prepay reduces balance, keep tenure same (reduce EMI)
    newMonths  = origMonths;
    const r2   = r, n2 = origMonths, b2 = P - prep;
    newEmi     = b2 * r2 * Math.pow(1+r2,n2) / (Math.pow(1+r2,n2)-1);
    savedInt   = origInt - (newEmi * n2 - (P - prep));
    savedMonths = 0;
    newTenureLabel = `${Math.floor(newMonths/12)}yr ${newMonths%12}mo (unchanged)`;
    newEmiLabel    = `New EMI: ₹${Math.round(newEmi).toLocaleString('en-IN')}/mo`;
  }

  function fmtR(v){if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr';if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+Math.round(v).toLocaleString('en-IN');}

  document.getElementById('ppResult').style.display = '';
  document.getElementById('ppEmisSaved').textContent  = savedMonths > 0 ? `${savedMonths} months` : '0';
  document.getElementById('ppIntSaved').textContent   = isFinite(savedInt) ? fmtR(savedInt) : '—';
  document.getElementById('ppNewTenure').textContent  = newTenureLabel;
  document.getElementById('ppReturnPct').textContent  = `${rAnn.toFixed(2)}%`;
  document.getElementById('ppComparision').innerHTML  =
    `<b>Before:</b> ${Math.floor(origMonths/12)}yr ${origMonths%12}mo remaining, Total interest: ${fmtR(origInt)}<br>
     <b>After:</b> ${newEmiLabel}, Total interest: ${isFinite(savedInt)?fmtR(origInt - savedInt):'—'}<br>
     <b>💡 Tip:</b> Prepaying ₹1 saves ₹${(rAnn/100).toFixed(4)} interest per year — equivalent to a ${rAnn.toFixed(2)}% guaranteed tax-free return.`;

  // Build chart data
  function buildBalances(startBal, monthlyEmi, months) {
    let bal = startBal, labels = [], vals = [];
    for (let m = 0; m <= Math.min(months, 360); m++) {
      if (m % 12 === 0 || m === months) { labels.push(`Yr ${Math.floor(m/12)}`); vals.push(Math.max(0, Math.round(bal))); }
      if (m < months) { bal -= (monthlyEmi - bal * r); }
    }
    return {labels, vals};
  }
  const withoutPrep = buildBalances(P,        emi, origMonths);
  const withPrep    = buildBalances(P - prep,  strat === 'reduce_tenure' ? emi : newEmi, newMonths);
  const allLabels   = [...new Set([...withoutPrep.labels, ...withPrep.labels])].sort((a,b)=>parseInt(a)-parseInt(b));

  document.getElementById('ppChartWrap').style.display = '';
  if (_ppChart) _ppChart.destroy();
  const ctx = document.getElementById('ppChart').getContext('2d');
  _ppChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: withoutPrep.labels,
      datasets: [
        {label:'Without Prepayment', data: withoutPrep.vals, borderColor:'#dc2626', backgroundColor:'rgba(220,38,38,.08)', fill:true, tension:.3},
        {label:'With Prepayment',    data: withPrep.vals.slice(0, withoutPrep.labels.length), borderColor:'#16a34a', backgroundColor:'rgba(22,163,74,.1)', fill:true, tension:.3}
      ]
    },
    options: {responsive:true, plugins:{legend:{position:'top'}}, scales:{y:{ticks:{callback:v=>v>=1e5?'₹'+(v/1e5).toFixed(0)+'L':v}}}}
  });
}

/* ─── t327: Home Loan Tax Benefit ─── */
function calcHomeLoanTax() {
  const interest   = parseFloat(document.getElementById('hltInterest').value) || 0;
  const principal  = parseFloat(document.getElementById('hltPrincipal').value) || 0;
  const taxPct     = parseFloat(document.getElementById('hltTaxBracket').value) || 30;
  const propType   = document.getElementById('hltPropType').value;
  const firstHome  = document.getElementById('hltFirstHome').value;
  const other80C   = parseFloat(document.getElementById('hltOther80C').value) || 0;
  const isJoint    = document.getElementById('hltJoint').value === 'yes';
  const rent       = parseFloat(document.getElementById('hltRent').value) || 0;

  if (!interest && !principal) { document.getElementById('hltResult').style.display='none'; return; }

  function fmtI(v){if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+Math.round(v).toLocaleString('en-IN');}
  const taxMult = taxPct / 100 * 1.04; // 4% cess approx

  // 24(b) — Interest deduction
  let interestLimit, interestDeducted, interestNote;
  if (propType === 'self') {
    interestLimit   = 200000;
    interestDeducted = Math.min(interest, interestLimit);
    interestNote    = `Self-occupied: capped at ₹2L/yr`;
  } else {
    // Let-out: no cap (but set-off of HP loss capped at ₹2L against other income)
    interestDeducted = interest;
    interestNote    = `Let-out: full interest deductible (HP loss set-off ₹2L cap)`;
  }
  const tax24b = interestDeducted * taxMult;

  // 80C — Principal repayment (within ₹1.5L overall 80C limit)
  const remaining80C = Math.max(0, 150000 - other80C);
  const principalDeducted = Math.min(principal, remaining80C);
  const tax80c = principalDeducted * taxMult;
  const principal80cNote = other80C > 0
    ? `80C room left: ₹${Math.round(remaining80C).toLocaleString('en-IN')} (after ₹${Math.round(other80C).toLocaleString('en-IN')} other)`
    : `80C limit: ₹1.5L`;

  // 80EE / 80EEA
  let eeDeducted = 0, eeLabel = '—', eeNote = 'Not applicable';
  if (firstHome === '80ee') {
    eeDeducted = Math.min(Math.max(0, interest - interestDeducted), 50000);
    eeLabel = fmtI(eeDeducted);
    eeNote = `80EE: extra ₹50K above 24(b) limit`;
  } else if (firstHome === '80eea') {
    eeDeducted = Math.min(Math.max(0, interest - interestDeducted), 150000);
    eeLabel = fmtI(eeDeducted);
    eeNote = `80EEA: extra ₹1.5L above 24(b) limit`;
  }
  const taxEE = eeDeducted * taxMult;

  const totalDeduction = interestDeducted + principalDeducted + eeDeducted;
  const totalTaxSaved  = tax24b + tax80c + taxEE;

  // Effective rate calc (needs loan outstanding — use interest/rate estimate)
  // We show effective cost = interest paid - tax saved / principal (approximate)
  const netInterestCost = interest - totalTaxSaved;

  document.getElementById('hltResult').style.display = '';
  document.getElementById('hlt24b').textContent     = fmtI(interestDeducted);
  document.getElementById('hlt24bNote').textContent = interestNote;
  document.getElementById('hlt80c').textContent     = fmtI(principalDeducted);
  document.getElementById('hlt80cNote').textContent = principal80cNote;
  document.getElementById('hlt80ee').textContent    = eeLabel;
  document.getElementById('hlt80eeNote').textContent= eeNote;
  document.getElementById('hltTaxSaved').textContent  = fmtI(totalTaxSaved);
  document.getElementById('hltTaxSavedNote').textContent = `at ${taxPct}% + 4% cess`;
  document.getElementById('hltEffRate').textContent  = interest > 0
    ? `Net cost: ${fmtI(netInterestCost)}/yr`
    : '—';

  // Breakdown table
  const rows = [
    ['Section 24(b)', 'Home loan interest', fmtI(interest), fmtI(interestDeducted), `<strong style="color:#16a34a;">${fmtI(tax24b)}</strong>`],
    ['Section 80C',   'Principal repayment (within 80C limit)', fmtI(principal), fmtI(principalDeducted), `<strong style="color:#16a34a;">${fmtI(tax80c)}</strong>`],
  ];
  if (eeDeducted > 0) {
    rows.push([firstHome === '80ee' ? '80EE' : '80EEA', 'First-time buyer bonus interest', fmtI(interest - interestDeducted), fmtI(eeDeducted), `<strong style="color:#16a34a;">${fmtI(taxEE)}</strong>`]);
  }
  rows.push(['TOTAL', '', '', fmtI(totalDeduction), `<strong style="color:#dc2626;font-size:14px;">${fmtI(totalTaxSaved)}</strong>`]);

  document.getElementById('hltBreakdownBody').innerHTML = rows.map((r,i) =>
    `<tr style="${i===rows.length-1?'background:var(--bg-secondary);font-weight:700;':''}border-bottom:1px solid var(--border-color);">
      <td style="padding:7px 12px;color:#6366f1;">${r[0]}</td>
      <td style="padding:7px 12px;color:var(--text-muted);">${r[1]}</td>
      <td style="padding:7px 12px;text-align:right;">${r[2]}</td>
      <td style="padding:7px 12px;text-align:right;">${r[3]}</td>
      <td style="padding:7px 12px;text-align:right;">${r[4]}</td>
    </tr>`
  ).join('');

  // Let-out HP computation
  const hpSection = document.getElementById('hltHPSection');
  if (propType === 'let' && rent > 0) {
    hpSection.style.display = '';
    const stdDeduction  = rent * 0.30;
    const municipalTax  = 0; // user doesn't provide, note it
    const netAnnualValue = rent - municipalTax;
    const incomeFromHP  = netAnnualValue - stdDeduction - interest;
    const hpLossSetOff  = incomeFromHP < 0 ? Math.min(-incomeFromHP, 200000) : 0;
    const carriedForward = incomeFromHP < 0 ? Math.max(0, -incomeFromHP - 200000) : 0;
    document.getElementById('hltHPBody').innerHTML = `
      <div>Annual Rent Received: <strong>${fmtI(rent)}</strong></div>
      <div>Less: Municipal Tax: <strong>₹0</strong> <span style="color:var(--text-muted);">(enter separately if applicable)</span></div>
      <div>Net Annual Value (NAV): <strong>${fmtI(netAnnualValue)}</strong></div>
      <div>Less: Standard Deduction (30% of NAV): <strong>${fmtI(stdDeduction)}</strong></div>
      <div>Less: Interest on Loan (Section 24b, no cap): <strong>${fmtI(interest)}</strong></div>
      <div style="border-top:1px solid var(--border-color);margin-top:6px;padding-top:6px;">
        <strong>Income / (Loss) from House Property: <span style="color:${incomeFromHP<0?'#dc2626':'#16a34a'}">${fmtI(incomeFromHP)}</span></strong>
      </div>
      ${incomeFromHP < 0 ? `
      <div style="margin-top:8px;color:var(--text-muted);">
        HP Loss set-off against other income (capped ₹2L): <strong style="color:#16a34a;">${fmtI(hpLossSetOff)}</strong><br>
        ${carriedForward > 0 ? `Carried forward (8 years): <strong>${fmtI(carriedForward)}</strong>` : ''}
        Tax saved from HP loss set-off: <strong style="color:#16a34a;">${fmtI(hpLossSetOff * taxMult)}</strong>
      </div>` : ''}`;
  } else {
    hpSection.style.display = 'none';
  }

  // Joint loan note
  const jointEl = document.getElementById('hltJointNote');
  if (isJoint) {
    jointEl.style.display = '';
    jointEl.innerHTML = `<strong>👫 Joint Loan Benefit:</strong> Each co-borrower can independently claim:<br>
      • 24(b): up to ₹2L interest each → combined up to <strong>₹4L</strong><br>
      • 80C: up to ₹1.5L principal each → combined up to <strong>₹3L</strong><br>
      • Total combined tax saving: up to <strong style="color:#6366f1;">${fmtI(totalTaxSaved * 2)}</strong> (if both at ${taxPct}% slab)`;
  } else {
    jointEl.style.display = 'none';
  }

  // ITR note
  document.getElementById('hltITRNote').innerHTML = `<strong>📋 Where to report in ITR:</strong>
    Section 24(b) interest → Schedule HP (House Property) |
    80C principal → Part C: Deductions |
    ${firstHome !== 'no' ? '80EE/80EEA → Chapter VI-A deductions |' : ''}
    Lender's <strong>Home Loan Interest Certificate</strong> is required as proof.`;
}


let _lviChart = null;
function calcLoanVsInvest() {
  const loanRate  = parseFloat(document.getElementById('lviLoanRate').value) || 0;
  const invReturn = parseFloat(document.getElementById('lviInvReturn').value) || 0;
  const surplus   = parseFloat(document.getElementById('lviSurplus').value) || 0;
  const outstanding = parseFloat(document.getElementById('lviOutstanding').value) || 0;
  const tenureYrs = parseFloat(document.getElementById('lviTenure').value) || 10;
  const taxPct    = parseFloat(document.getElementById('lviTaxBracket').value) || 0;
  const loanType  = document.getElementById('lviLoanType').value;
  const invType   = document.getElementById('lviInvType').value;

  if (!loanRate || !invReturn || !surplus) {
    document.getElementById('lviResult').style.display = 'none';
    return;
  }

  // Effective loan rate after tax benefit
  let effectiveLoanRate = loanRate;
  let taxSavingNote = '';
  if (loanType === 'home') {
    // 24(b) interest deduction up to ₹2L/yr at slab rate
    const annualInterest = outstanding * (loanRate / 100);
    const deductionLimit = Math.min(annualInterest, 200000);
    const taxSaving = deductionLimit * (taxPct / 100);
    const netInterest = annualInterest - taxSaving;
    effectiveLoanRate = (netInterest / outstanding) * 100;
    taxSavingNote = `Home loan: 24(b) saves ~₹${Math.round(taxSaving).toLocaleString('en-IN')}/yr → effective rate ${effectiveLoanRate.toFixed(2)}%`;
  } else if (loanType === 'education') {
    effectiveLoanRate = loanRate * (1 - taxPct / 100);
    taxSavingNote = `Education loan: 80E deduction → effective rate ${effectiveLoanRate.toFixed(2)}%`;
  }

  // Effective investment return after tax
  let effectiveInvReturn = invReturn;
  let invTaxNote = '';
  if (invType === 'equity') {
    // LTCG 12.5% above ₹1.25L
    const annualGain = surplus * (invReturn / 100);
    const taxableGain = Math.max(0, annualGain - 125000);
    const tax = taxableGain * 0.125;
    effectiveInvReturn = ((annualGain - tax) / surplus) * 100;
    invTaxNote = `Equity LTCG 12.5% → effective return ${effectiveInvReturn.toFixed(2)}%`;
  } else if (invType === 'debt') {
    effectiveInvReturn = invReturn * (1 - taxPct / 100);
    invTaxNote = `Debt/FD at ${taxPct}% slab → effective return ${effectiveInvReturn.toFixed(2)}%`;
  } else if (invType === 'elss') {
    // 80C saves up to ₹1.5L, then LTCG 12.5%
    const taxSaving80C = Math.min(surplus, 150000) * (taxPct / 100);
    const annualGain = surplus * (invReturn / 100);
    const taxableGain = Math.max(0, annualGain - 125000);
    const ltcgTax = taxableGain * 0.125;
    const netGain = annualGain - ltcgTax + taxSaving80C / tenureYrs;
    effectiveInvReturn = (netGain / surplus) * 100;
    invTaxNote = `ELSS: 80C saves ₹${Math.round(taxSaving80C).toLocaleString('en-IN')} + LTCG 12.5% → eff. return ${effectiveInvReturn.toFixed(2)}%`;
  }

  // Wealth at end of tenure
  const prepayWealth  = surplus * Math.pow(1 + effectiveLoanRate/100, tenureYrs); // "guaranteed savings"
  const investWealth  = surplus * Math.pow(1 + effectiveInvReturn/100, tenureYrs);

  function fmtR(v){if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+'Cr';if(v>=1e5)return'₹'+(v/1e5).toFixed(1)+'L';return'₹'+Math.round(v).toLocaleString('en-IN');}

  const betterInvest  = effectiveInvReturn > effectiveLoanRate;
  const diff          = Math.abs(investWealth - prepayWealth);
  const margin        = Math.abs(effectiveInvReturn - effectiveLoanRate);

  document.getElementById('lviResult').style.display = '';

  const verdictEl = document.getElementById('lviVerdict');
  if (betterInvest) {
    verdictEl.style.background = 'rgba(22,163,74,.12)';
    verdictEl.style.border = '2px solid #16a34a';
    verdictEl.innerHTML = `<div style="font-size:28px;margin-bottom:6px;">📈 INVEST the Surplus</div>
      <div style="font-size:15px;color:#16a34a;font-weight:700;">You gain ~${fmtR(diff)} more by investing over ${tenureYrs} years</div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">Eff. invest return (${effectiveInvReturn.toFixed(2)}%) > Eff. loan cost (${effectiveLoanRate.toFixed(2)}%) by ${margin.toFixed(2)}pp</div>`;
  } else {
    verdictEl.style.background = 'rgba(220,38,38,.10)';
    verdictEl.style.border = '2px solid #dc2626';
    verdictEl.innerHTML = `<div style="font-size:28px;margin-bottom:6px;">💰 PREPAY the Loan</div>
      <div style="font-size:15px;color:#dc2626;font-weight:700;">You save ~${fmtR(diff)} more by prepaying over ${tenureYrs} years</div>
      <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">Eff. loan cost (${effectiveLoanRate.toFixed(2)}%) ≥ Eff. invest return (${effectiveInvReturn.toFixed(2)}%)</div>`;
  }

  document.getElementById('lviPrepayDetails').innerHTML = `
    <div style="font-size:12px;line-height:2;">
      <div>Loan Rate: <strong>${loanRate}%</strong></div>
      <div>Effective Rate (after tax): <strong>${effectiveLoanRate.toFixed(2)}%</strong></div>
      <div>Guaranteed Saving: <strong style="color:#dc2626;">${fmtR(prepayWealth)}</strong></div>
      ${taxSavingNote ? `<div style="color:var(--text-muted);font-size:11px;margin-top:6px;">${taxSavingNote}</div>` : ''}
    </div>`;

  document.getElementById('lviInvestDetails').innerHTML = `
    <div style="font-size:12px;line-height:2;">
      <div>Expected Return: <strong>${invReturn}%</strong></div>
      <div>Effective Return (after tax): <strong>${effectiveInvReturn.toFixed(2)}%</strong></div>
      <div>Invested Value: <strong style="color:#16a34a;">${fmtR(investWealth)}</strong></div>
      ${invTaxNote ? `<div style="color:var(--text-muted);font-size:11px;margin-top:6px;">${invTaxNote}</div>` : ''}
    </div>`;

  // Chart
  const years = Array.from({length: Math.ceil(tenureYrs)+1}, (_, i) => `Yr ${i}`);
  const prepayLine = years.map((_,i) => Math.round(surplus * Math.pow(1 + effectiveLoanRate/100, i)));
  const investLine = years.map((_,i) => Math.round(surplus * Math.pow(1 + effectiveInvReturn/100, i)));
  if (_lviChart) _lviChart.destroy();
  const ctx2 = document.getElementById('lviChart').getContext('2d');
  _lviChart = new Chart(ctx2, {
    type: 'line',
    data: {
      labels: years,
      datasets: [
        {label:'Prepay (guaranteed savings)', data: prepayLine, borderColor:'#dc2626', backgroundColor:'rgba(220,38,38,.08)', fill:true, tension:.3},
        {label:'Invest (projected)',          data: investLine, borderColor:'#16a34a', backgroundColor:'rgba(22,163,74,.08)', fill:true, tension:.3},
      ]
    },
    options: {responsive:true, plugins:{legend:{position:'top'}}, scales:{y:{ticks:{callback:v=>v>=1e5?'₹'+(v/1e5).toFixed(0)+'L':v}}}}
  });

  // Factors
  const factors = [];
  if (margin < 2) factors.push('⚠️ Margin is thin (<2pp) — consider risk tolerance before deciding.');
  if (loanType === 'home') factors.push('🏠 Home loan enjoys 24(b) interest deduction — reduces effective cost.');
  if (invType === 'equity') factors.push('📊 Equity returns are variable — 12% is historical avg, not guaranteed.');
  if (taxPct === 30) factors.push('💼 High tax bracket: debt investment returns are significantly eroded by tax.');
  factors.push('🧘 Peace of mind from being debt-free has intangible value — factor this in your decision.');
  document.getElementById('lviFactors').innerHTML = `<strong>Key Factors to Consider:</strong><ul style="margin:8px 0 0 16px;line-height:1.8;">${factors.map(f=>`<li>${f}</li>`).join('')}</ul>`;
}
</script>

<!-- ══════════════════════════════════════════════════════════════════════════
     t464: HOME LOAN EMI TRACKER — Modal overlay panel
══════════════════════════════════════════════════════════════════════════ -->
<div id="hlPanel" style="display:none;position:fixed;inset:0;z-index:1100;background:rgba(0,0,0,.5);overflow-y:auto;">
  <div style="max-width:960px;margin:24px auto;padding:0 12px 60px;">
    <!-- Header -->
    <div style="background:var(--bg);border-radius:14px;padding:20px 24px;margin-bottom:16px;display:flex;align-items:center;gap:16px;">
      <div style="flex:1;">
        <h2 style="margin:0;font-size:18px;font-weight:700;">🏠 Home Loan EMI Tracker</h2>
        <p style="margin:4px 0 0;font-size:13px;color:var(--text-muted);">EMI calendar · Rate history · Prepayments · Tax deductions</p>
      </div>
      <select id="hlLoanSelect" class="form-select" style="min-width:240px;" onchange="HL.loadLoan()">
        <option value="">— Select Home Loan —</option>
      </select>
      <button onclick="HL.close()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-muted);">✕</button>
    </div>

    <!-- Loan Overview Cards -->
    <div id="hlOverviewCards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:16px;"></div>

    <!-- Sub-tabs -->
    <div style="display:flex;gap:0;border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:16px;background:var(--bg);">
      <button class="hl-sub" data-tab="calendar" onclick="HL.subTab('calendar',this)" style="flex:1;padding:10px;border:none;cursor:pointer;font-size:13px;font-weight:600;background:var(--primary);color:#fff;">📅 EMI Calendar</button>
      <button class="hl-sub" data-tab="prepay"   onclick="HL.subTab('prepay',this)"   style="flex:1;padding:10px;border:none;cursor:pointer;font-size:13px;font-weight:600;background:none;border-left:1px solid var(--border);">💰 Prepayments</button>
      <button class="hl-sub" data-tab="rates"    onclick="HL.subTab('rates',this)"    style="flex:1;padding:10px;border:none;cursor:pointer;font-size:13px;font-weight:600;background:none;border-left:1px solid var(--border);">📈 Rate History</button>
      <button class="hl-sub" data-tab="tax"      onclick="HL.subTab('tax',this)"      style="flex:1;padding:10px;border:none;cursor:pointer;font-size:13px;font-weight:600;background:none;border-left:1px solid var(--border);">🧾 Tax Claims</button>
      <button class="hl-sub" data-tab="details"  onclick="HL.subTab('details',this)"  style="flex:1;padding:10px;border:none;cursor:pointer;font-size:13px;font-weight:600;background:none;border-left:1px solid var(--border);">ℹ️ Details</button>
    </div>

    <!-- ── EMI CALENDAR ── -->
    <div id="hlTabCalendar" class="hl-tab-panel" style="background:var(--bg);border-radius:12px;padding:20px;">
      <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
        <label style="font-size:13px;">Show months:</label>
        <select id="hlCalMonths" class="form-select" style="width:120px;" onchange="HL.loadCalendar()">
          <option value="12">12 months</option>
          <option value="24" selected>24 months</option>
          <option value="36">36 months</option>
          <option value="60">60 months</option>
        </select>
        <div id="hlCalTotals" style="font-size:13px;color:var(--text-muted);"></div>
      </div>
      <div style="overflow-x:auto;">
        <table class="table" id="hlCalTable">
          <thead>
            <tr>
              <th>Month</th><th>Due Date</th>
              <th class="text-right">EMI</th>
              <th class="text-right">Principal</th>
              <th class="text-right">Interest</th>
              <th class="text-right">Balance</th>
              <th class="text-center">Status</th>
            </tr>
          </thead>
          <tbody id="hlCalBody"><tr><td colspan="7" class="text-center" style="padding:32px;color:var(--text-muted);">Select a home loan above.</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ── PREPAYMENTS ── -->
    <div id="hlTabPrepay" class="hl-tab-panel" style="display:none;background:var(--bg);border-radius:12px;padding:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div id="hlPrepayTotals" style="font-size:13px;"></div>
        <button class="btn btn-primary btn-sm" onclick="HL.openPrepayForm()" id="hlBtnAddPrepay" disabled>+ Log Prepayment</button>
      </div>
      <table class="table">
        <thead><tr><th>Date</th><th>Amount</th><th>Type</th><th>Impact</th><th class="text-right">EMIs Saved</th><th class="text-right">Interest Saved</th><th>Source</th><th class="text-center">Actions</th></tr></thead>
        <tbody id="hlPrepayBody"><tr><td colspan="8" class="text-center" style="padding:32px;color:var(--text-muted);">Select a loan first.</td></tr></tbody>
      </table>
    </div>

    <!-- ── RATE HISTORY ── -->
    <div id="hlTabRates" class="hl-tab-panel" style="display:none;background:var(--bg);border-radius:12px;padding:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <p style="margin:0;font-size:13px;color:var(--text-muted);">Track floating rate changes over time</p>
        <button class="btn btn-primary btn-sm" onclick="HL.openRateForm()" id="hlBtnAddRate" disabled>+ Record Rate Change</button>
      </div>
      <table class="table">
        <thead><tr><th>Effective Date</th><th class="text-right">Old Rate</th><th class="text-right">New Rate</th><th class="text-right">New EMI</th><th>Reason</th><th class="text-center">Actions</th></tr></thead>
        <tbody id="hlRateBody"><tr><td colspan="6" class="text-center" style="padding:32px;color:var(--text-muted);">Select a loan first.</td></tr></tbody>
      </table>
    </div>

    <!-- ── TAX CLAIMS ── -->
    <div id="hlTabTax" class="hl-tab-panel" style="display:none;background:var(--bg);border-radius:12px;padding:20px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div style="font-size:13px;">
          <strong>Section 24(b):</strong> Interest deduction up to ₹2L (self-occupied) · <strong>Section 80C:</strong> Principal repayment up to ₹1.5L
        </div>
        <button class="btn btn-primary btn-sm" onclick="HL.openTaxForm()" id="hlBtnAddTax" disabled>+ Add FY Record</button>
      </div>
      <table class="table">
        <thead>
          <tr>
            <th>FY</th>
            <th class="text-right">Interest Paid</th><th class="text-right">24(b) Claimed</th>
            <th class="text-right">Principal Paid</th><th class="text-right">80C Claimed</th>
            <th class="text-right">Tax Saved (est.)</th>
            <th class="text-center">U/C</th><th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody id="hlTaxBody"><tr><td colspan="8" class="text-center" style="padding:32px;color:var(--text-muted);">Select a loan first.</td></tr></tbody>
      </table>
    </div>

    <!-- ── DETAILS ── -->
    <div id="hlTabDetails" class="hl-tab-panel" style="display:none;background:var(--bg);border-radius:12px;padding:20px;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div>
          <label class="form-label">Property Name</label>
          <input type="text" id="hlDtlProperty" class="form-input" placeholder="My Home / Flat 4B...">
        </div>
        <div>
          <label class="form-label">Co-borrower Name</label>
          <input type="text" id="hlDtlCoBorrower" class="form-input" placeholder="Spouse / Partner...">
        </div>
        <div style="grid-column:span 2;">
          <label class="form-label">Property Address</label>
          <textarea id="hlDtlAddress" class="form-input" rows="2" placeholder="Full address..."></textarea>
        </div>
        <div>
          <label class="form-label">Loan Sanction Date</label>
          <input type="date" id="hlDtlSanctionDate" class="form-input">
        </div>
        <div>
          <label class="form-label">Moratorium Period (months)</label>
          <input type="number" id="hlDtlMoratorium" class="form-input" placeholder="0" min="0" max="48">
        </div>
        <div>
          <label class="form-label">Base Rate Type</label>
          <select id="hlDtlBaseRate" class="form-select">
            <option value="">— Select —</option>
            <option value="RLLR">RLLR (Repo Linked)</option>
            <option value="MCLR">MCLR</option>
            <option value="PLR">PLR (Old)</option>
            <option value="Fixed">Fixed Rate</option>
          </select>
        </div>
        <div>
          <label class="form-label">Spread / Margin (%)</label>
          <input type="number" id="hlDtlSpread" class="form-input" placeholder="e.g. 2.65" step="0.01">
        </div>
        <div>
          <label class="form-label">Next Rate Reset Date</label>
          <input type="date" id="hlDtlResetDate" class="form-input">
        </div>
      </div>
      <div style="margin-top:16px;text-align:right;">
        <button class="btn btn-primary" onclick="HL.saveDetails()" id="hlBtnSaveDetails" disabled>Save Details</button>
      </div>
    </div>
  </div>
</div>

<!-- ─── Prepayment Modal ─── -->
<div id="hlPrepayModal" class="modal-overlay" style="display:none;">
  <div class="modal" style="max-width:500px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">💰 Log Prepayment</h3>
      <button class="modal-close" onclick="document.getElementById('hlPrepayModal').style.display='none'">✕</button>
    </div>
    <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
      <div>
        <label class="form-label">Payment Date *</label>
        <input type="date" id="hlPmDate" class="form-input" value="<?= date('Y-m-d') ?>">
      </div>
      <div>
        <label class="form-label">Amount (₹) *</label>
        <input type="number" id="hlPmAmount" class="form-input" placeholder="0" oninput="HL.previewPrepay()">
      </div>
      <div>
        <label class="form-label">Type</label>
        <select id="hlPmMode" class="form-select">
          <option value="partial_prepayment">Partial Prepayment</option>
          <option value="full_prepayment">Full Prepayment</option>
          <option value="balance_transfer">Balance Transfer</option>
        </select>
      </div>
      <div>
        <label class="form-label">Prefer to</label>
        <select id="hlPmImpact" class="form-select" onchange="HL.previewPrepay()">
          <option value="reduce_tenure">Reduce Tenure (same EMI)</option>
          <option value="reduce_emi">Reduce EMI (same tenure)</option>
        </select>
      </div>
      <div>
        <label class="form-label">Penalty Charged (₹)</label>
        <input type="number" id="hlPmPenalty" class="form-input" placeholder="0">
      </div>
      <div>
        <label class="form-label">Source of Funds</label>
        <input type="text" id="hlPmSource" class="form-input" placeholder="Bonus, savings...">
      </div>
      <div style="grid-column:span 2;" id="hlPmPreview" style="padding:10px;background:var(--bg-secondary);border-radius:8px;font-size:13px;display:none;"></div>
      <div style="grid-column:span 2;">
        <label class="form-label">Notes</label>
        <input type="text" id="hlPmNotes" class="form-input" placeholder="Optional notes">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="document.getElementById('hlPrepayModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary" onclick="HL.savePrepayment()">Log Prepayment</button>
    </div>
  </div>
</div>

<!-- ─── Rate Change Modal ─── -->
<div id="hlRateModal" class="modal-overlay" style="display:none;">
  <div class="modal" style="max-width:480px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">📈 Record Rate Change</h3>
      <button class="modal-close" onclick="document.getElementById('hlRateModal').style.display='none'">✕</button>
    </div>
    <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
      <div>
        <label class="form-label">Effective Date *</label>
        <input type="date" id="hlRmDate" class="form-input" value="<?= date('Y-m-d') ?>">
      </div>
      <div>
        <label class="form-label">Old Rate (%) *</label>
        <input type="number" id="hlRmOld" class="form-input" placeholder="8.50" step="0.01">
      </div>
      <div>
        <label class="form-label">New Rate (%) *</label>
        <input type="number" id="hlRmNew" class="form-input" placeholder="8.75" step="0.01" oninput="HL.previewRate()">
      </div>
      <div>
        <label class="form-label">RBI Base Rate (%)</label>
        <input type="number" id="hlRmBase" class="form-input" placeholder="6.50" step="0.01">
      </div>
      <div>
        <label class="form-label">New EMI (if EMI changes)</label>
        <input type="number" id="hlRmEmi" class="form-input" placeholder="Auto-calc or enter">
      </div>
      <div>
        <label class="form-label">New Tenure (if tenure changes)</label>
        <input type="number" id="hlRmTenure" class="form-input" placeholder="months remaining">
      </div>
      <div style="grid-column:span 2;" id="hlRmPreview" style="padding:10px;background:var(--bg-secondary);border-radius:8px;font-size:13px;display:none;"></div>
      <div style="grid-column:span 2;">
        <label class="form-label">Reason</label>
        <input type="text" id="hlRmReason" class="form-input" placeholder="RBI repo cut, annual reset...">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="document.getElementById('hlRateModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary" onclick="HL.saveRate()">Save Rate Change</button>
    </div>
  </div>
</div>

<!-- ─── Tax Claim Modal ─── -->
<div id="hlTaxModal" class="modal-overlay" style="display:none;">
  <div class="modal" style="max-width:480px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">🧾 Home Loan Tax Claim</h3>
      <button class="modal-close" onclick="document.getElementById('hlTaxModal').style.display='none'">✕</button>
    </div>
    <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
      <div style="grid-column:span 2;">
        <label class="form-label">Financial Year *</label>
        <select id="hlTmFy" class="form-select">
          <?php
          $fyYear = date('n') >= 4 ? date('Y') : date('Y') - 1;
          for ($fy = $fyYear; $fy >= $fyYear - 7; $fy--) {
            $label = $fy . '-' . substr($fy+1, 2);
            echo "<option value=\"{$label}\">{$label}</option>";
          }
          ?>
        </select>
      </div>
      <div>
        <label class="form-label">Interest Paid (₹)</label>
        <input type="number" id="hlTmIntPaid" class="form-input" placeholder="0">
      </div>
      <div>
        <label class="form-label">24(b) Claimed (₹) <span style="color:#6366f1;" title="Max ₹2L self-occupied">ⓘ</span></label>
        <input type="number" id="hlTmSec24b" class="form-input" placeholder="max 200000">
      </div>
      <div>
        <label class="form-label">Principal Paid (₹)</label>
        <input type="number" id="hlTmPrinPaid" class="form-input" placeholder="0">
      </div>
      <div>
        <label class="form-label">80C Claimed (₹) <span style="color:#6366f1;" title="Part of ₹1.5L 80C limit">ⓘ</span></label>
        <input type="number" id="hlTmSec80c" class="form-input" placeholder="max 150000">
      </div>
      <div style="grid-column:span 2;display:flex;align-items:center;gap:10px;">
        <input type="checkbox" id="hlTmUC">
        <label for="hlTmUC" style="font-size:13px;">Property was under construction this FY (interest deduction deferred)</label>
      </div>
      <div style="grid-column:span 2;">
        <label class="form-label">Notes</label>
        <input type="text" id="hlTmNotes" class="form-input" placeholder="Form 16 reference, CA notes...">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="document.getElementById('hlTaxModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary" onclick="HL.saveTaxClaim()">Save Tax Claim</button>
    </div>
  </div>
</div>

<script>
/* ═══════════════════════════════════════════════════════════════════════════
   t464: Home Loan EMI Tracker — HL namespace
═══════════════════════════════════════════════════════════════════════════ */
const HL = (() => {
  'use strict';

  let _loanId  = null;
  let _loan    = null;   // full detail object
  let _prepays = [];
  let _rates   = [];
  let _taxes   = [];
  let _subTab  = 'calendar';

  const $ = id => document.getElementById(id);
  const fmtI = v => {
    const n = Math.abs(parseFloat(v) || 0);
    if (n >= 1e7) return '₹' + (n/1e7).toFixed(2) + 'Cr';
    if (n >= 1e5) return '₹' + (n/1e5).toFixed(1) + 'L';
    return '₹' + n.toLocaleString('en-IN', {maximumFractionDigits:0});
  };
  const fmtD = d => d ? new Date(d).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'}) : '—';
  const fmtPct = v => (parseFloat(v)||0).toFixed(2) + '%';

  // ── Open / close panel ──────────────────────────────────────────────────
  function open() {
    $('hlPanel').style.display = 'block';
    document.body.style.overflow = 'hidden';
    // Populate selector from existing _loanData (already loaded on page)
    if (typeof _loanData !== 'undefined') {
      const sel = $('hlLoanSelect');
      const prev = sel.value;
      sel.innerHTML = '<option value="">— Select Home Loan —</option>';
      (_loanData || []).filter(l => l.loan_type === 'home').forEach(l => {
        const opt = document.createElement('option');
        opt.value = l.id;
        opt.textContent = `${l.lender} · ${fmtI(l.outstanding)} outstanding`;
        sel.appendChild(opt);
      });
      if (prev) sel.value = prev;
    }
  }

  function close() {
    $('hlPanel').style.display = 'none';
    document.body.style.overflow = '';
  }

  // ── Load full loan detail ────────────────────────────────────────────────
  async function loadLoan() {
    _loanId = parseInt($('hlLoanSelect').value) || null;
    ['hlBtnAddPrepay','hlBtnAddRate','hlBtnAddTax','hlBtnSaveDetails'].forEach(id => {
      $(id).disabled = !_loanId;
    });
    $('hlOverviewCards').innerHTML = '';
    if (!_loanId) return;

    const res = await fetch(`${APP_URL}/api/router.php?action=hl_detail&id=${_loanId}`).then(r=>r.json());
    if (!res.success) { showToast('Failed to load loan details.', 'error'); return; }
    _loan = res.data;
    renderOverview();

    // Load sub-tab data
    await Promise.all([loadCalendar(), loadPrepayments(), loadRates(), loadTaxClaims()]);
    fillDetails();
  }

  // ── Overview cards ──────────────────────────────────────────────────────
  function renderOverview() {
    const l = _loan;
    const remaining = parseInt(l.remaining_months) || 0;
    const yrs = Math.floor(remaining / 12), mos = remaining % 12;
    const remStr = yrs > 0 ? `${yrs}y ${mos}m` : `${mos}m`;
    const pctDone = l.principal_amount > 0 ? ((l.principal_amount - l.outstanding_balance) / l.principal_amount * 100).toFixed(1) : 0;

    $('hlOverviewCards').innerHTML = `
      <div class="stat-card">
        <div class="stat-label">Outstanding</div>
        <div class="stat-value text-danger">${fmtI(l.outstanding_balance)}</div>
        <div style="font-size:11px;color:var(--text-muted);">${pctDone}% paid off</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Monthly EMI</div>
        <div class="stat-value">${fmtI(l.emi_amount)}</div>
        <div style="font-size:11px;color:var(--text-muted);">Interest: ${fmtI(l.monthly_interest)}/mo</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Remaining</div>
        <div class="stat-value">${remStr}</div>
        <div style="font-size:11px;color:var(--text-muted);">${remaining} EMIs left</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Rate</div>
        <div class="stat-value">${fmtPct(l.interest_rate)}</div>
        <div style="font-size:11px;color:var(--text-muted);">${l.rate_type || 'fixed'}${l.base_rate_type ? ' · '+l.base_rate_type : ''}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Total Int. Paid</div>
        <div class="stat-value">${fmtI(l.total_interest_paid)}</div>
        <div style="font-size:11px;color:var(--text-muted);">Principal: ${fmtI(l.total_principal_paid)}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Future Interest</div>
        <div class="stat-value text-danger">${fmtI(l.total_future_interest)}</div>
        <div style="font-size:11px;color:var(--text-muted);">If no prepayments</div>
      </div>
    `;
  }

  // ── EMI Calendar ─────────────────────────────────────────────────────────
  async function loadCalendar() {
    if (!_loanId) return;
    const months = $('hlCalMonths').value || 24;
    const res = await fetch(`${APP_URL}/api/router.php?action=hl_emi_calendar&loan_id=${_loanId}&months=${months}`).then(r=>r.json());
    if (!res.success) return;
    const d = res.data;
    const today = new Date().toISOString().split('T')[0];

    $('hlCalTotals').innerHTML = `Showing ${d.months_shown} months · Total EMI: <strong>${fmtI(d.total_emi)}</strong> · Interest: <strong style="color:#ef4444;">${fmtI(d.total_interest)}</strong> · Principal: <strong style="color:#16a34a;">${fmtI(d.total_principal)}</strong>`;

    $('hlCalBody').innerHTML = (d.calendar || []).map(row => {
      const isDue = row.due_date === today;
      const isOverdue = row.is_overdue;
      const rowStyle = isDue ? 'background:rgba(99,102,241,.08);font-weight:600;'
                     : isOverdue ? 'background:rgba(239,68,68,.05);color:var(--text-muted);'
                     : row.month_num % 12 === 0 ? 'background:rgba(16,185,129,.05);font-weight:600;' : '';
      const prinPct = row.emi > 0 ? (row.principal / row.emi * 100).toFixed(0) : 0;
      return `<tr style="${rowStyle}">
        <td><strong>${row.month}</strong>${isDue ? ' <span style="font-size:10px;background:#6366f1;color:#fff;padding:1px 5px;border-radius:4px;">TODAY</span>' : ''}</td>
        <td>${fmtD(row.due_date)}</td>
        <td class="text-right">${fmtI(row.emi)}</td>
        <td class="text-right">
          <span style="color:#16a34a;">${fmtI(row.principal)}</span>
          <div style="height:3px;background:var(--border);border-radius:2px;margin-top:2px;"><div style="width:${prinPct}%;height:100%;background:#16a34a;border-radius:2px;"></div></div>
        </td>
        <td class="text-right" style="color:#ef4444;">${fmtI(row.interest)}</td>
        <td class="text-right">${fmtI(row.balance)}</td>
        <td class="text-center">${isOverdue ? '⚠️' : isDue ? '📌' : '⏳'}</td>
      </tr>`;
    }).join('') || '<tr><td colspan="7" class="text-center" style="padding:24px;color:var(--text-muted);">No EMIs to show.</td></tr>';
  }

  // ── Prepayments ──────────────────────────────────────────────────────────
  async function loadPrepayments() {
    if (!_loanId) return;
    const res = await fetch(`${APP_URL}/api/router.php?action=hl_prepayments&loan_id=${_loanId}`).then(r=>r.json());
    if (!res.success) return;
    _prepays = res.data.rows || [];

    $('hlPrepayTotals').innerHTML = _prepays.length
      ? `Total prepaid: <strong style="color:#16a34a;">${fmtI(res.data.total_prepaid)}</strong> · Interest saved: <strong style="color:#6366f1;">${fmtI(res.data.interest_saved)}</strong>`
      : '<span style="color:var(--text-muted);">No prepayments logged yet.</span>';

    const modeLabel = {partial_prepayment:'Partial', full_prepayment:'Full Closure', balance_transfer:'BT'};
    $('hlPrepayBody').innerHTML = _prepays.length
      ? _prepays.map(p => `<tr>
          <td>${fmtD(p.payment_date)}</td>
          <td><strong style="color:#16a34a;">${fmtI(p.amount)}</strong></td>
          <td><span style="font-size:11px;padding:2px 6px;background:var(--bg-secondary);border-radius:4px;">${modeLabel[p.mode]||p.mode}</span></td>
          <td style="font-size:12px;">${p.impact==='reduce_tenure'?'📉 Reduce tenure':'💳 Reduce EMI'}</td>
          <td class="text-right">${p.emis_saved || '—'}</td>
          <td class="text-right" style="color:#6366f1;">${p.interest_saved ? fmtI(p.interest_saved) : '—'}</td>
          <td style="font-size:12px;color:var(--text-muted);">${p.source || '—'}</td>
          <td class="text-center"><button class="btn btn-xs btn-ghost" style="color:#ef4444;" onclick="HL.delPrepay(${p.id})">✕</button></td>
        </tr>`).join('')
      : '<tr><td colspan="8" class="text-center" style="padding:24px;color:var(--text-muted);">No prepayments yet.</td></tr>';
  }

  // ── Rate history ─────────────────────────────────────────────────────────
  async function loadRates() {
    if (!_loanId) return;
    const res = await fetch(`${APP_URL}/api/router.php?action=hl_rate_history&loan_id=${_loanId}`).then(r=>r.json());
    if (!res.success) return;
    _rates = res.data || [];

    $('hlRateBody').innerHTML = _rates.length
      ? _rates.map(r => {
          const diff = (parseFloat(r.new_rate) - parseFloat(r.old_rate)).toFixed(2);
          const arrow = parseFloat(diff) > 0 ? '↑' : parseFloat(diff) < 0 ? '↓' : '→';
          const col   = parseFloat(diff) > 0 ? '#ef4444' : '#16a34a';
          return `<tr>
            <td>${fmtD(r.effective_date)}</td>
            <td class="text-right">${fmtPct(r.old_rate)}</td>
            <td class="text-right"><strong style="color:${col};">${arrow} ${fmtPct(r.new_rate)}</strong> <span style="font-size:11px;color:${col};">(${parseFloat(diff)>0?'+':''}${diff}%)</span></td>
            <td class="text-right">${r.new_emi ? fmtI(r.new_emi) : '—'}</td>
            <td style="font-size:12px;color:var(--text-muted);">${r.reason || '—'}</td>
            <td class="text-center"><button class="btn btn-xs btn-ghost" style="color:#ef4444;" onclick="HL.delRate(${r.id})">✕</button></td>
          </tr>`;
        }).join('')
      : '<tr><td colspan="6" class="text-center" style="padding:24px;color:var(--text-muted);">No rate changes recorded.</td></tr>';
  }

  // ── Tax claims ───────────────────────────────────────────────────────────
  async function loadTaxClaims() {
    if (!_loanId) return;
    const res = await fetch(`${APP_URL}/api/router.php?action=hl_tax_claims&loan_id=${_loanId}`).then(r=>r.json());
    if (!res.success) return;
    _taxes = res.data || [];

    $('hlTaxBody').innerHTML = _taxes.length
      ? _taxes.map(t => {
          const taxSaved = (parseFloat(t.sec_24b_claimed) + parseFloat(t.sec_80c_claimed)) * 0.3;
          return `<tr>
            <td><strong>${t.fy}</strong></td>
            <td class="text-right">${fmtI(t.interest_paid)}</td>
            <td class="text-right" style="color:#6366f1;">${fmtI(t.sec_24b_claimed)}</td>
            <td class="text-right">${fmtI(t.principal_paid)}</td>
            <td class="text-right" style="color:#6366f1;">${fmtI(t.sec_80c_claimed)}</td>
            <td class="text-right" style="color:#16a34a;font-weight:600;">${fmtI(taxSaved)}</td>
            <td class="text-center">${t.under_construction ? '🏗️' : '—'}</td>
            <td class="text-center"><button class="btn btn-xs btn-ghost" onclick="HL.openTaxForm(${JSON.stringify(t).replace(/"/g,'&quot;')})">✎</button></td>
          </tr>`;
        }).join('')
      : '<tr><td colspan="8" class="text-center" style="padding:24px;color:var(--text-muted);">No tax claims recorded yet.</td></tr>';
  }

  // ── Fill details form ────────────────────────────────────────────────────
  function fillDetails() {
    if (!_loan) return;
    $('hlDtlProperty').value      = _loan.property_name || '';
    $('hlDtlCoBorrower').value    = _loan.co_borrower || '';
    $('hlDtlAddress').value       = _loan.property_address || '';
    $('hlDtlSanctionDate').value  = _loan.loan_sanction_date ? _loan.loan_sanction_date.split(' ')[0] : '';
    $('hlDtlMoratorium').value    = _loan.moratorium_months || 0;
    $('hlDtlBaseRate').value      = _loan.base_rate_type || '';
    $('hlDtlSpread').value        = _loan.spread_pct || '';
    $('hlDtlResetDate').value     = _loan.reset_date ? _loan.reset_date.split(' ')[0] : '';
  }

  // ── Sub-tab switcher ─────────────────────────────────────────────────────
  function subTab(tab, btn) {
    _subTab = tab;
    document.querySelectorAll('.hl-tab-panel').forEach(el => el.style.display = 'none');
    $('hlTab' + tab.charAt(0).toUpperCase() + tab.slice(1)).style.display = '';
    document.querySelectorAll('.hl-sub').forEach(b => { b.style.background = 'none'; b.style.color = ''; });
    if (btn) { btn.style.background = 'var(--primary)'; btn.style.color = '#fff'; }
    if (tab === 'calendar') loadCalendar();
  }

  // ── Modal openers ────────────────────────────────────────────────────────
  function openPrepayForm() { $('hlPrepayModal').style.display = 'flex'; }

  function previewPrepay() {
    if (!_loan) return;
    const amt    = parseFloat($('hlPmAmount').value) || 0;
    const impact = $('hlPmImpact').value;
    const prev   = $('hlPmPreview');
    if (!amt) { prev.style.display = 'none'; return; }
    const P  = parseFloat(_loan.outstanding_balance);
    const r  = parseFloat(_loan.interest_rate) / 100 / 12;
    const e  = parseFloat(_loan.emi_amount);
    const nB = r > 0 && e > 0 && P > 0 ? Math.ceil(Math.log(e/(e - P*r)) / Math.log(1+r)) : 0;
    const nP = Math.max(0, P - amt);
    const nA = impact === 'reduce_tenure' && r > 0 && e > 0 && nP > 0 ? Math.ceil(Math.log(e/(e - nP*r)) / Math.log(1+r)) : nB;
    const saved = Math.max(0, nB - nA);
    const intSaved = Math.max(0, e * nB - P) - Math.max(0, e * nA - nP);
    prev.style.display = '';
    prev.innerHTML = `💡 <strong>Impact Preview:</strong> ${saved} EMIs saved · Interest saved: <strong style="color:#6366f1;">${fmtI(intSaved)}</strong>${impact==='reduce_emi' ? ` · New EMI: <strong>${fmtI(nP * r * Math.pow(1+r,nB) / (Math.pow(1+r,nB)-1))}</strong>` : ''}`;
  }

  function openRateForm()  { $('hlRateModal').style.display = 'flex'; }

  function previewRate() {
    if (!_loan) return;
    const newRate = parseFloat($('hlRmNew').value) || 0;
    if (!newRate) { $('hlRmPreview').style.display = 'none'; return; }
    const P   = parseFloat(_loan.outstanding_balance);
    const r   = newRate / 100 / 12;
    const n   = parseInt(_loan.remaining_months) || 0;
    const newEmi = n > 0 && r > 0 ? P * r * Math.pow(1+r,n) / (Math.pow(1+r,n)-1) : 0;
    $('hlRmEmi').value = Math.round(newEmi);
    $('hlRmPreview').style.display = '';
    $('hlRmPreview').innerHTML = `💡 New EMI at ${newRate}%: <strong>${fmtI(newEmi)}/mo</strong> · Change: <strong style="color:${newEmi > parseFloat(_loan.emi_amount)?'#ef4444':'#16a34a'};">${fmtI(Math.abs(newEmi - parseFloat(_loan.emi_amount)))} ${newEmi > parseFloat(_loan.emi_amount)?'more':'less'}</strong>`;
  }

  function openTaxForm(t) {
    if (t) {
      $('hlTmFy').value       = t.fy;
      $('hlTmIntPaid').value  = t.interest_paid || '';
      $('hlTmSec24b').value   = t.sec_24b_claimed || '';
      $('hlTmPrinPaid').value = t.principal_paid || '';
      $('hlTmSec80c').value   = t.sec_80c_claimed || '';
      $('hlTmUC').checked     = t.under_construction == 1;
      $('hlTmNotes').value    = t.notes || '';
    } else {
      ['hlTmIntPaid','hlTmSec24b','hlTmPrinPaid','hlTmSec80c','hlTmNotes'].forEach(id => $(id).value = '');
      $('hlTmUC').checked = false;
    }
    $('hlTaxModal').style.display = 'flex';
  }

  // ── Savers ───────────────────────────────────────────────────────────────
  async function savePrepayment() {
    const amt = parseFloat($('hlPmAmount').value);
    if (!amt || amt <= 0) { alert('Enter a valid amount.'); return; }
    const res = await apiPost({
      action: 'hl_prepayment_add', loan_id: _loanId,
      payment_date: $('hlPmDate').value, amount: amt,
      mode: $('hlPmMode').value, impact: $('hlPmImpact').value,
      penalty_charged: $('hlPmPenalty').value || 0,
      source: $('hlPmSource').value, notes: $('hlPmNotes').value,
    });
    if (res.success) {
      $('hlPrepayModal').style.display = 'none';
      showToast(`Prepayment logged! ${res.data.emis_saved} EMIs saved 🎉`, 'success');
      await loadLoan();
      if (typeof loadLoans === 'function') loadLoans(); // refresh main table
    } else { alert(res.message); }
  }

  async function saveRate() {
    const newRate = parseFloat($('hlRmNew').value);
    if (!newRate) { alert('New rate required.'); return; }
    const res = await apiPost({
      action: 'hl_rate_add', loan_id: _loanId,
      effective_date: $('hlRmDate').value,
      old_rate: $('hlRmOld').value,
      new_rate: newRate,
      new_emi: $('hlRmEmi').value,
      new_tenure: $('hlRmTenure').value,
      base_rate: $('hlRmBase').value,
      reason: $('hlRmReason').value,
    });
    if (res.success) {
      $('hlRateModal').style.display = 'none';
      showToast('Rate change recorded.', 'success');
      await loadLoan();
      if (typeof loadLoans === 'function') loadLoans();
    } else { alert(res.message); }
  }

  async function saveTaxClaim() {
    const fy = $('hlTmFy').value;
    if (!fy) { alert('Select a FY.'); return; }
    const res = await apiPost({
      action: 'hl_tax_claim_save', loan_id: _loanId, fy,
      interest_paid: $('hlTmIntPaid').value || 0,
      sec_24b_claimed: $('hlTmSec24b').value || 0,
      principal_paid: $('hlTmPrinPaid').value || 0,
      sec_80c_claimed: $('hlTmSec80c').value || 0,
      under_construction: $('hlTmUC').checked ? '1' : '0',
      notes: $('hlTmNotes').value,
    });
    if (res.success) {
      $('hlTaxModal').style.display = 'none';
      showToast('Tax claim saved.', 'success');
      await loadTaxClaims();
    } else { alert(res.message); }
  }

  async function saveDetails() {
    const res = await apiPost({
      action: 'hl_update_details', id: _loanId,
      property_name: $('hlDtlProperty').value,
      co_borrower: $('hlDtlCoBorrower').value,
      property_address: $('hlDtlAddress').value,
      loan_sanction_date: $('hlDtlSanctionDate').value,
      moratorium_months: $('hlDtlMoratorium').value || 0,
      base_rate_type: $('hlDtlBaseRate').value,
      spread_pct: $('hlDtlSpread').value,
      reset_date: $('hlDtlResetDate').value,
    });
    if (res.success) {
      showToast('Details saved.', 'success');
      await loadLoan();
    } else { alert(res.message); }
  }

  // ── Deleters ─────────────────────────────────────────────────────────────
  async function delPrepay(id) {
    if (!confirm('Delete this prepayment record?')) return;
    await apiPost({ action: 'hl_prepayment_delete', id });
    await loadPrepayments();
  }
  async function delRate(id) {
    if (!confirm('Delete this rate change entry?')) return;
    await apiPost({ action: 'hl_rate_delete', id });
    await loadRates();
  }

  return {
    open, close, loadLoan, loadCalendar, subTab,
    openPrepayForm, previewPrepay, savePrepayment, delPrepay,
    openRateForm, previewRate, saveRate, delRate,
    openTaxForm, saveTaxClaim, saveDetails,
  };
})();
</script>
<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>
