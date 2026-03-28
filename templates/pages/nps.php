<?php
/**
 * WealthDash — NPS (National Pension System) Page
 * Phase 4 — Complete: Holdings + Contributions + NAV Update
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'NPS Holdings';
$activePage  = 'nps';

$db = DB::conn();

$summaryStmt = $db->prepare("
    SELECT COUNT(DISTINCT h.scheme_id) AS scheme_count,
           SUM(h.total_invested) AS total_invested,
           SUM(h.latest_value)   AS latest_value,
           SUM(h.gain_loss)      AS gain_loss
    FROM nps_holdings h
    JOIN portfolios p ON p.id = h.portfolio_id
    WHERE p.user_id = ?
");
$summaryStmt->execute([$currentUser['id']]);
$summary       = $summaryStmt->fetch();
$totalInvested = (float)($summary['total_invested'] ?? 0);
$latestValue   = (float)($summary['latest_value'] ?? 0);
$gainLoss      = (float)($summary['gain_loss'] ?? 0);
$gainPct       = $totalInvested > 0 ? round(($gainLoss / $totalInvested) * 100, 2) : 0;
$schemeCount   = (int)($summary['scheme_count'] ?? 0);

$portfolioId = get_user_portfolio_id((int)$currentUser['id']);

$schemesAll = $db->query("SELECT id, pfm_name, scheme_name, tier, latest_nav, latest_nav_date FROM nps_schemes ORDER BY pfm_name, tier, scheme_name")->fetchAll();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">NPS — National Pension System</h1>
    <p class="page-subtitle">Tier I &amp; Tier II holdings with PFRDA NAV</p>
  </div>
  <div class="page-header-actions">
    <!-- Statement Download Dropdown (t101) -->
    <div style="position:relative;display:inline-block" id="npsStmtDropWrap">
      <button class="btn btn-ghost" id="btnNpsStmt" onclick="NPS.toggleStmtDrop()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Statement ▾
      </button>
      <div id="npsStmtDrop" style="display:none;position:absolute;top:100%;right:0;background:var(--bg-card);border:1.5px solid var(--border-color);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:200;min-width:200px;padding:6px 0;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);padding:4px 14px 6px;text-transform:uppercase;letter-spacing:.4px">Download Statement</div>
        <a id="npsStmtCsv" href="#" onclick="NPS.downloadStatement('csv'); return false;" style="display:flex;align-items:center;gap:8px;padding:8px 14px;font-size:12px;font-weight:600;color:var(--text-primary);text-decoration:none;transition:background .1s" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background=''">
          📊 Download CSV
        </a>
        <a id="npsStmtPdf" href="#" onclick="NPS.downloadStatement('pdf'); return false;" style="display:flex;align-items:center;gap:8px;padding:8px 14px;font-size:12px;font-weight:600;color:var(--text-primary);text-decoration:none;transition:background .1s" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background=''">
          🖨️ Print / Save PDF
        </a>
        <a id="npsStmtHtml" href="#" onclick="NPS.downloadStatement('html'); return false;" style="display:flex;align-items:center;gap:8px;padding:8px 14px;font-size:12px;font-weight:600;color:var(--text-primary);text-decoration:none;transition:background .1s" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background=''">
          📄 View HTML
        </a>
        <div style="border-top:1px solid var(--border-color);margin:6px 0;"></div>
        <div style="padding:4px 14px 4px;font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px">Filter by FY</div>
        <select id="npsStmtFy" style="margin:2px 10px 8px;padding:5px 8px;border-radius:6px;border:1px solid var(--border-color);background:var(--bg-secondary);color:var(--text-primary);font-size:11px;width:calc(100% - 20px)">
          <option value="">All Years</option>
          <?php
          $fyList = DB::fetchAll("SELECT DISTINCT investment_fy FROM nps_transactions WHERE investment_fy IS NOT NULL ORDER BY investment_fy DESC LIMIT 10");
          foreach ($fyList as $fy): ?><option value="<?= e($fy['investment_fy']) ?>"><?= e($fy['investment_fy']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <button class="btn btn-ghost" id="btnNavUpdate">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.12"/></svg>
      Refresh NAV
    </button>
    <button class="btn btn-primary" id="btnAddNps">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Contribution
    </button>
  </div>
</div>

<div class="stats-grid" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label">Active Schemes</div>
    <div class="stat-value"><?= $schemeCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Invested</div>
    <div class="stat-value"><?= inr($totalInvested) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Current Value</div>
    <div class="stat-value"><?= inr($latestValue) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Gain / Loss</div>
    <div class="stat-value <?= $gainLoss >= 0 ? 'text-success' : 'text-danger' ?>">
      <?= inr($gainLoss) ?>
      <span class="stat-sub"><?= ($gainLoss >= 0 ? '+' : '') . $gainPct ?>%</span>
    </div>
  </div>
</div>

<!-- t103 + t104: Asset Allocation + Tax Dashboard -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

  <!-- t103: Asset Allocation Donut -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">📊 Asset Allocation (E/C/G/A)</h3>
    </div>
    <div class="card-body" style="display:flex;align-items:center;gap:20px;padding:16px;">
      <div style="position:relative;width:140px;height:140px;flex-shrink:0;">
        <canvas id="npsAllocChart" width="140" height="140"></canvas>
        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;">
          <div id="npsAllocCenter" style="font-size:11px;color:var(--text-muted);text-align:center;">Loading...</div>
        </div>
      </div>
      <div id="npsAllocLegend" style="flex:1;display:flex;flex-direction:column;gap:6px;font-size:12px;"></div>
    </div>
  </div>

  <!-- t104: NPS Tax Dashboard -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">🧾 NPS Tax Deductions</h3>
      <span style="font-size:11px;color:var(--text-muted);" id="npsTaxFy"></span>
    </div>
    <div class="card-body" style="padding:16px;">
      <!-- 80C portion -->
      <div style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
          <span style="font-weight:600;">80C — Employee (combined limit ₹1.5L)</span>
          <span id="nps80cAmt" style="font-weight:700;">₹0</span>
        </div>
        <div style="height:7px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
          <div id="nps80cBar" style="height:100%;width:0%;background:#3b82f6;border-radius:99px;transition:width .5s;"></div>
        </div>
      </div>
      <!-- 80CCD(1B) -->
      <div style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
          <span style="font-weight:600;">80CCD(1B) — Extra NPS (limit ₹50K)</span>
          <span id="nps80ccdAmt" style="font-weight:700;">₹0</span>
        </div>
        <div style="height:7px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
          <div id="nps80ccdBar" style="height:100%;width:0%;background:#8b5cf6;border-radius:99px;transition:width .5s;"></div>
        </div>
      </div>
      <!-- 80CCD(2) employer -->
      <div style="margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
          <span style="font-weight:600;">80CCD(2) — Employer (10% of salary)</span>
          <span id="nps80ccd2Amt" style="font-weight:700;">₹0</span>
        </div>
        <div style="height:7px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
          <div id="nps80ccd2Bar" style="height:100%;width:0%;background:#10b981;border-radius:99px;transition:width .5s;"></div>
        </div>
      </div>
      <div id="npsTaxMsg" style="font-size:11px;padding:6px 10px;border-radius:6px;background:rgba(59,130,246,.07);color:var(--text-muted);"></div>
    </div>
  </div>

</div>

<!-- Holdings Table -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div style="display:flex;gap:6px;">
      <button class="nps-tier-btn active" data-tier="" onclick="NPS.setTierFilter('',this)">All</button>
      <button class="nps-tier-btn" data-tier="tier1" onclick="NPS.setTierFilter('tier1',this)">Tier I</button>
      <button class="nps-tier-btn" data-tier="tier2" onclick="NPS.setTierFilter('tier2',this)">Tier II</button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Scheme / PFM</th>
          <th class="text-center">Value / P&L</th>
          <th class="text-center">Return / XIRR</th>
          <th class="text-center">Units</th>
          <th class="text-center">NAV (₹)</th>
          <th class="text-center">Since</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="npsHoldingsBody">
        <tr><td colspan="7" class="text-center" style="padding:40px;color:var(--text-muted)">
          <span class="spinner"></span> Loading holdings...
        </td></tr>
      </tbody>
    </table>
  </div>
  <div id="npsPagWrap" style="padding:0 16px;"></div>
</div>

<!-- Contribution History -->
<div class="card" style="margin-top:24px">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <h3 class="card-title">Contribution History</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <select class="form-select" id="txnFilterScheme" style="width:200px">
        <option value="">All Schemes</option>
      </select>
      <select class="form-select" id="txnFilterTier" style="width:120px">
        <option value="">All Tiers</option>
        <option value="tier1">Tier I</option>
        <option value="tier2">Tier II</option>
      </select>
      <select class="form-select" id="txnFilterType" style="width:140px">
        <option value="">All Types</option>
        <option value="SELF">Self</option>
        <option value="EMPLOYER">Employer</option>
      </select>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Scheme</th>
          <th>Tier</th>
          <th>Type</th>
          <th class="text-right">Units</th>
          <th class="text-right">NAV</th>
          <th class="text-right">Amount</th>
          <th>FY</th>
          <th class="text-center">Del</th>
        </tr>
      </thead>
      <tbody id="npsTxnBody">
        <tr><td colspan="9" class="text-center" style="padding:40px;color:var(--text-muted)">
          <span class="spinner"></span> Loading contributions...
        </td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- t107: NPS Maturity Calculator -->
<div class="card" style="margin-top:24px;">
  <div class="card-header">
    <h3 class="card-title">🎯 NPS Maturity Calculator — Retirement Corpus Projection</h3>
  </div>
  <div class="card-body" style="padding:16px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px;">
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Current Age</div>
        <input type="number" id="npsCalcAge" value="30" min="18" max="70" class="form-input" oninput="calcNpsMaturity()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Retirement Age</div>
        <input type="number" id="npsCalcRetAge" value="60" min="40" max="70" class="form-input" oninput="calcNpsMaturity()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Monthly Contribution (₹)</div>
        <input type="number" id="npsCalcContrib" value="10000" class="form-input" oninput="calcNpsMaturity()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Current NPS Value (₹)</div>
        <input type="number" id="npsCalcCurrent" placeholder="Auto from holdings" class="form-input" oninput="calcNpsMaturity()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Expected Return (% p.a.)</div>
        <select id="npsCalcReturn" class="form-input" onchange="calcNpsMaturity()">
          <option value="8">8% (Conservative)</option>
          <option value="10" selected>10% (Moderate)</option>
          <option value="12">12% (Aggressive)</option>
        </select>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Annual Step-Up (%)</div>
        <select id="npsCalcStepup" class="form-input" onchange="calcNpsMaturity()">
          <option value="0">No step-up</option>
          <option value="5" selected>5% per year</option>
          <option value="10">10% per year</option>
        </select>
      </div>
    </div>
    <div id="npsCalcResult" style="display:none;"></div>
  </div>
</div>

<!-- t105: NPS vs MF vs PPF Comparison -->
<div class="card" style="margin-top:24px;">
  <div class="card-header">
    <h3 class="card-title">⚖️ NPS vs ELSS vs PPF — After-Tax Comparison</h3>
    <span style="font-size:11px;color:var(--text-muted);">Same amount, same duration, compare final corpus</span>
  </div>
  <div class="card-body" style="padding:16px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Monthly Investment (₹)</label>
        <input type="number" id="cmpMonthly" value="10000" class="form-input" oninput="calcNpsMfCmp()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Investment Period (years)</label>
        <input type="number" id="cmpYears" value="25" min="5" max="40" class="form-input" oninput="calcNpsMfCmp()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Expected Return (%)</label>
        <select id="cmpReturn" class="form-input" onchange="calcNpsMfCmp()">
          <option value="10">10% Conservative</option>
          <option value="12" selected>12% Moderate</option>
          <option value="14">14% Aggressive</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Your Tax Slab</label>
        <select id="cmpSlab" class="form-input" onchange="calcNpsMfCmp()">
          <option value="0.20">20% slab</option>
          <option value="0.30" selected>30% slab</option>
        </select>
      </div>
    </div>
    <div id="cmpResult"></div>
  </div>
</div>

<!-- Add Contribution Modal -->
<div class="modal-overlay" id="modalAddNps" style="display:none">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3 class="modal-title">Add NPS Contribution</h3>
      <button class="modal-close" id="closeModalNps">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Tier *</label>
          <select class="form-select" id="npsTier" onchange="NPS.filterSchemes()">
            <option value="tier1">Tier I</option>
            <option value="tier2">Tier II</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Contribution Type *</label>
          <select class="form-select" id="npsContribType">
            <option value="SELF">Self</option>
            <option value="EMPLOYER">Employer</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">NPS Scheme (PFM) *</label>
        <select class="form-select" id="npsScheme" onchange="NPS.onSchemeChange()">
          <option value="">— Select Scheme —</option>
        </select>
        <small style="color:var(--text-muted)">NAV auto-fills from latest available</small>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Transaction Date *</label>
          <input type="date" class="form-input" id="npsTxnDate" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">NAV (₹) *</label>
          <input type="number" class="form-input" id="npsNav" step="0.0001" min="0.0001" placeholder="e.g. 32.4582">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Units *</label>
          <input type="number" class="form-input" id="npsUnits" step="0.0001" min="0.0001" placeholder="0.0000" oninput="NPS.calcAmount()">
        </div>
        <div class="form-group">
          <label class="form-label">Amount (₹)</label>
          <input type="number" class="form-input" id="npsAmount" step="0.01" min="0" placeholder="Auto-calculated" oninput="NPS.calcUnits()">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" class="form-input" id="npsNotes" placeholder="Optional">
      </div>
      <div id="npsError" class="form-error" style="display:none"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelNps">Cancel</button>
      <button class="btn btn-primary" id="saveNps">Save Contribution</button>
    </div>
  </div>
</div>

<!-- Delete Confirm -->
<div class="modal-overlay" id="modalDelNps" style="display:none">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <h3 class="modal-title">Delete Contribution?</h3>
      <button class="modal-close" id="closeDelNps">✕</button>
    </div>
    <div class="modal-body"><p>This will permanently delete this NPS contribution and recalculate holdings.</p></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelDelNps">Cancel</button>
      <button class="btn btn-danger" id="confirmDelNps">Delete</button>
    </div>
  </div>
</div>

<style>
/* ── NPS Tier Filter Buttons ── */
.nps-tier-btn {
  padding: 6px 18px;
  border-radius: 8px;
  border: 1.5px solid var(--border-color, #e2e8f0);
  background: var(--bg-secondary, #f8fafc);
  color: var(--text-muted, #64748b);
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: all .15s;
  letter-spacing: .2px;
}
.nps-tier-btn:hover {
  border-color: var(--accent, #2563eb);
  color: var(--accent, #2563eb);
}
.nps-tier-btn.active {
  background: var(--accent, #2563eb);
  color: #fff;
  border-color: var(--accent, #2563eb);
  box-shadow: 0 2px 8px rgba(37,99,235,.18);
}

/* ── Themed Select Dropdowns ── */
/* form-select optgroup/option theming */
.form-select option,
.form-select optgroup {
  background: var(--bg-card, #fff);
  color: var(--text-primary, #1e293b);
}
</style>

<script>window.NPS_SCHEMES_DATA = <?= json_encode($schemesAll, JSON_HEX_TAG) ?>;
window.NPS_PORTFOLIO_ID = <?= json_encode($portfolioId) ?>;

// t107: NPS Maturity Calculator
function calcNpsMaturity() {
  const age      = parseInt(document.getElementById('npsCalcAge')?.value)     || 30;
  const retAge   = parseInt(document.getElementById('npsCalcRetAge')?.value)  || 60;
  const contrib  = parseFloat(document.getElementById('npsCalcContrib')?.value)|| 10000;
  const current  = parseFloat(document.getElementById('npsCalcCurrent')?.value)|| 0;
  const rate     = (parseFloat(document.getElementById('npsCalcReturn')?.value)|| 10) / 100;
  const stepup   = (parseFloat(document.getElementById('npsCalcStepup')?.value)|| 5)  / 100;
  const res      = document.getElementById('npsCalcResult');
  if (!res || retAge <= age) return;

  const years = retAge - age;
  const r     = rate / 12;
  // Step-up SIP projection year by year
  let corpus = current;
  let monthlyContrib = contrib;
  for (let y = 0; y < years; y++) {
    // Grow existing corpus for 12 months
    corpus = corpus * Math.pow(1 + r, 12);
    // Add 12 months of contributions
    corpus += monthlyContrib * ((Math.pow(1+r,12) - 1) / r) * (1+r);
    // Step up for next year
    monthlyContrib *= (1 + stepup);
  }

  // NPS at maturity: 60% lump sum + 40% annuity
  const lumpsum = corpus * 0.60;
  const annuity = corpus * 0.40;
  const monthlyPension = annuity * 0.0525 / 12; // ~5.25% annuity rate
  // Inflation-adjusted (assume 6% inflation)
  const realCorpus = corpus / Math.pow(1.06, years);
  const totalInvested = contrib * 12 * years; // simplified

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + ' Cr';
    return '₹' + (v/1e5).toFixed(1) + 'L';
  }

  res.style.display = '';
  res.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
      <div style="background:rgba(59,130,246,.07);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Total Corpus @ ${retAge}</div>
        <div style="font-size:22px;font-weight:800;color:#3b82f6;">${fmtI(corpus)}</div>
        <div style="font-size:11px;color:var(--text-muted);">${years} years</div>
      </div>
      <div style="background:rgba(22,163,74,.07);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Lump Sum (60%)</div>
        <div style="font-size:18px;font-weight:800;color:#16a34a;">${fmtI(lumpsum)}</div>
        <div style="font-size:11px;color:var(--text-muted);">Withdraw at 60</div>
      </div>
      <div style="background:rgba(139,92,246,.07);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Monthly Pension</div>
        <div style="font-size:18px;font-weight:800;color:#8b5cf6;">${fmtI(monthlyPension)}<span style="font-size:11px;">/mo</span></div>
        <div style="font-size:11px;color:var(--text-muted);">@5.25% annuity on 40%</div>
      </div>
      <div style="background:rgba(245,158,11,.07);border-radius:10px;padding:14px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Real Value (6% inflation)</div>
        <div style="font-size:18px;font-weight:800;color:#d97706;">${fmtI(realCorpus)}</div>
        <div style="font-size:11px;color:var(--text-muted);">Today's purchasing power</div>
      </div>
    </div>
    <div style="height:8px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;margin-bottom:6px;">
      <div style="height:100%;width:${Math.min(100,(totalInvested/corpus*100)).toFixed(0)}%;background:#3b82f6;border-radius:99px;"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);">
      <span>Total Invested: ${fmtI(totalInvested)}</span>
      <span>Returns: ${fmtI(corpus - totalInvested - current)} (${((corpus/Math.max(1,totalInvested+current)-1)*100).toFixed(0)}%)</span>
    </div>`;
}

// t105: NPS vs MF vs PPF After-Tax Comparison
function calcNpsMfCmp() {
  const monthly = parseFloat(document.getElementById('cmpMonthly')?.value) || 10000;
  const years   = parseInt(document.getElementById('cmpYears')?.value)    || 25;
  const ret     = (parseFloat(document.getElementById('cmpReturn')?.value) || 12) / 100;
  const slab    = parseFloat(document.getElementById('cmpSlab')?.value)   || 0.30;
  const res     = document.getElementById('cmpResult');
  if (!res) return;

  const r = ret / 12, n = years * 12;
  // Gross corpus for all (same pre-tax investment)
  const grossCorpus = monthly * ((Math.pow(1+r,n)-1)/r) * (1+r);

  // ── NPS ──
  // Tax saving: 80C (up to ₹1.5L/yr = ₹12,500/mo) + 80CCD(1B) (₹50K/yr = ₹4,167/mo)
  const annualInv = monthly * 12;
  const nps80c    = Math.min(annualInv, 150000) * slab;
  const nps80ccd  = Math.min(Math.max(0, annualInv - 150000), 50000) * slab;
  const npsTaxSaving = nps80c + nps80ccd;
  // Maturity: 60% lump sum (tax-free up to ₹7.5L from employer contrib) + 40% annuity (taxable)
  const npsLumpsum = grossCorpus * 0.60; // largely tax-free
  const npsAnnuityCorpus = grossCorpus * 0.40;
  const npsMonthlyPension = npsAnnuityCorpus * 0.0525 / 12;
  const npsAfterTax = npsLumpsum + npsAnnuityCorpus; // annuity taxed but ongoing
  const npsTotalBenefit = npsAfterTax + npsTaxSaving * years; // cumulative tax saved

  // ── ELSS ──
  // 80C eligible: up to ₹1.5L/yr — same saving
  const elss80c = Math.min(annualInv, 150000) * slab;
  // LTCG @12.5% with ₹1.25L exemption per year
  const elssGain = grossCorpus - monthly * n;
  const elssExemption = 125000; // annual exemption
  const elssTaxableGain = Math.max(0, elssGain - elssExemption * years);
  const elssLtcgTax = elssTaxableGain * 0.125;
  const elssAfterTax = grossCorpus - elssLtcgTax;
  const elssTotalBenefit = elssAfterTax + elss80c * years;

  // ── PPF ──
  // PPF rate: 7.1%, EEE (fully tax free)
  const ppfR = 0.071 / 12;
  const ppfCorpus = monthly * ((Math.pow(1+ppfR,n)-1)/ppfR) * (1+ppfR);
  const ppf80c = Math.min(annualInv, 150000) * slab;
  const ppfAfterTax = ppfCorpus; // fully tax-free

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
  }

  const best = Math.max(npsTotalBenefit, elssTotalBenefit, ppfAfterTax + ppf80c*years);
  const isBestNps  = best === npsTotalBenefit;
  const isBestElss = best === elssTotalBenefit;
  const isBestPpf  = best === (ppfAfterTax + ppf80c*years);

  res.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
      <div style="background:${isBestNps?'rgba(22,163,74,.08)':'var(--bg-secondary)'};border-radius:10px;padding:14px;text-align:center;border:${isBestNps?'2px solid #86efac':'1.5px solid var(--border)'};">
        <div style="font-size:12px;font-weight:800;color:var(--text-muted);margin-bottom:8px;">🏛️ NPS (Tier I)</div>
        <div style="font-size:20px;font-weight:800;color:#3b82f6;">${fmtI(grossCorpus)}</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Corpus @60</div>
        <div style="margin-top:8px;font-size:11px;border-top:1px solid var(--border);padding-top:8px;">
          <div>Lump sum (60%): <strong>${fmtI(npsLumpsum)}</strong></div>
          <div>Pension: <strong>~${fmtI(npsMonthlyPension)}/mo</strong></div>
          <div style="color:#16a34a;">Tax saved: <strong>${fmtI(npsTaxSaving)}/yr</strong></div>
        </div>
        <div style="font-size:10px;color:#d97706;margin-top:6px;">⚠️ Lock-in till 60 · 40% annuity mandatory</div>
        ${isBestNps?'<div style="font-size:11px;font-weight:800;color:#16a34a;margin-top:6px;">🏆 Best Overall</div>':''}
      </div>
      <div style="background:${isBestElss?'rgba(22,163,74,.08)':'var(--bg-secondary)'};border-radius:10px;padding:14px;text-align:center;border:${isBestElss?'2px solid #86efac':'1.5px solid var(--border)'};">
        <div style="font-size:12px;font-weight:800;color:var(--text-muted);margin-bottom:8px;">📊 ELSS MF</div>
        <div style="font-size:20px;font-weight:800;color:#8b5cf6;">${fmtI(elssAfterTax)}</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">After LTCG tax</div>
        <div style="margin-top:8px;font-size:11px;border-top:1px solid var(--border);padding-top:8px;">
          <div>Gross corpus: <strong>${fmtI(grossCorpus)}</strong></div>
          <div style="color:#dc2626;">LTCG tax: <strong>-${fmtI(elssLtcgTax)}</strong></div>
          <div style="color:#16a34a;">80C saved: <strong>${fmtI(elss80c)}/yr</strong></div>
        </div>
        <div style="font-size:10px;color:#16a34a;margin-top:6px;">✅ 3yr lock-in · Full withdrawal</div>
        ${isBestElss?'<div style="font-size:11px;font-weight:800;color:#16a34a;margin-top:6px;">🏆 Best Overall</div>':''}
      </div>
      <div style="background:${isBestPpf?'rgba(22,163,74,.08)':'var(--bg-secondary)'};border-radius:10px;padding:14px;text-align:center;border:${isBestPpf?'2px solid #86efac':'1.5px solid var(--border)'};">
        <div style="font-size:12px;font-weight:800;color:var(--text-muted);margin-bottom:8px;">🛡️ PPF</div>
        <div style="font-size:20px;font-weight:800;color:#1d4ed8;">${fmtI(ppfCorpus)}</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Fully tax-free</div>
        <div style="margin-top:8px;font-size:11px;border-top:1px solid var(--border);padding-top:8px;">
          <div>Rate: <strong>7.1% (fixed)</strong></div>
          <div style="color:#16a34a;">EEE: <strong>100% tax-free</strong></div>
          <div style="color:#16a34a;">80C saved: <strong>${fmtI(ppf80c)}/yr</strong></div>
        </div>
        <div style="font-size:10px;color:#d97706;margin-top:6px;">⚠️ 15yr lock-in · Max ₹1.5L/yr</div>
        ${isBestPpf?'<div style="font-size:11px;font-weight:800;color:#16a34a;margin-top:6px;">🏆 Best Overall</div>':''}
      </div>
    </div>
    <div style="font-size:12px;padding:10px;background:rgba(99,102,241,.05);border-radius:7px;color:var(--text-muted);">
      💡 NPS wins at high income (30% slab) due to extra 80CCD(1B) ₹50K deduction. ELSS wins for flexibility. PPF wins for guaranteed + tax-free returns for risk-averse. Best strategy: NPS + ELSS both.
    </div>`;
}

document.addEventListener('DOMContentLoaded', () => {
  NPS.init();
  calcNpsMaturity();
  calcNpsMfCmp(); // t105
});
</script>
<?php
$pageContent = ob_get_clean();
$extraScripts = '<script src="' . APP_URL . '/public/js/charts.js?v=' . ASSET_VERSION . '"></script>'
             . '<script src="' . APP_URL . '/public/js/nps.js?v=' . ASSET_VERSION . '"></script>';
include APP_ROOT . '/templates/layout.php';