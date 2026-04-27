<?php
/**
 * WealthDash — Loan Tracker (t123)
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
<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>
