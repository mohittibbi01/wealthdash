<?php
/**
 * WealthDash — EPF Tracker + Interest Calculator (t46, t339)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';
$currentUser = require_auth();
$pageTitle = 'EPF Tracker'; $activePage = 'epf';
$db = DB::conn();
$pStmt = $db->prepare("SELECT id, name FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">🏢 EPF Tracker</h1><p class="page-subtitle">Employee Provident Fund · EPS · Interest Calculator</p></div>
  <div class="page-header-actions"><button class="btn btn-primary" onclick="openAddEpf()" id="btnAddEpf">+ Add EPF Account</button></div>
</div>

<!-- Tab Nav -->
<div class="tab-nav" style="margin-bottom:20px;border-bottom:1px solid var(--border-color);">
  <button class="tab-btn active" id="tabBtnTracker"  onclick="switchEpfTab('tracker')">📋 EPF Tracker</button>
  <button class="tab-btn"        id="tabBtnCalc"     onclick="switchEpfTab('calc')">🧮 Interest Calculator</button>
  <button class="tab-btn"        id="tabBtnGratuity" onclick="switchEpfTab('gratuity')">🏅 Gratuity Tracker</button>
  <button class="tab-btn"        id="tabBtnEps"      onclick="switchEpfTab('eps')">🏛️ EPS Pension</button>
  <button class="tab-btn"        id="tabBtnCombined" onclick="switchEpfTab('combined')">📊 Combined View</button>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 1: TRACKER
     ══════════════════════════════════════════════════════════ -->
<div id="tabTracker">
  <div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card"><div class="stat-label">Accounts</div><div class="stat-value" id="epfCount">—</div></div>
    <div class="stat-card"><div class="stat-label">EPF Balance</div><div class="stat-value text-success" id="epfBalance">—</div></div>
    <div class="stat-card"><div class="stat-label">EPS Balance</div><div class="stat-value" id="epfEps">—</div></div>
    <div class="stat-card"><div class="stat-label">Annual Contribution</div><div class="stat-value" id="epfContrib">—</div></div>
  </div>
  <div style="margin-bottom:12px;">
    <select id="epfFilterPortfolio" class="form-select" style="width:170px;" onchange="loadEpf()">
      <option value="">All Portfolios</option>
      <?php foreach ($portfolios as $p): ?>
      <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table">
        <thead><tr>
          <th>Employer / UAN</th><th class="text-right">Basic Salary</th>
          <th class="text-right">Employee (12%)</th><th class="text-right">Employer (3.67%)</th>
          <th class="text-right">EPS (8.33%)</th><th class="text-right">EPF Balance</th>
          <th class="text-right">EPS Balance</th><th>Service</th>
          <th class="text-center">Actions</th>
        </tr></thead>
        <tbody id="epfBody"><tr><td colspan="9" class="text-center" style="padding:40px;"><span class="spinner"></span></td></tr></tbody>
      </table>
    </div>
  </div>
  <div class="card mt-4" style="background:rgba(29,78,216,.04);border:1px solid rgba(29,78,216,.15);">
    <div class="card-body" style="padding:14px;">
      <div style="font-size:12px;color:var(--text-muted);line-height:1.8;">
        <strong>EPF Interest Rate FY 2024-25: 8.25% p.a.</strong> (compounded annually) ·
        Employee contribution: 12% of Basic+DA · Employer: 3.67% to EPF + 8.33% to EPS ·
        80C: employee contribution eligible (up to ₹1.5L combined) ·
        Withdrawal: fully tax-free after 5 years continuous service
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 2: INTEREST CALCULATOR (t339)
     ══════════════════════════════════════════════════════════ -->
<div id="tabCalc" style="display:none;">
  <div style="display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start;">

    <!-- Input Panel -->
    <div class="card" style="padding:18px;">
      <div style="font-size:14px;font-weight:600;margin-bottom:14px;color:var(--text-primary);">📊 Calculator Inputs</div>

      <div class="form-group">
        <label class="form-label">Basic + DA Salary (₹/month) *</label>
        <input type="number" id="cBasic" class="form-input" placeholder="e.g. 50000" value="50000">
      </div>
      <div class="form-group">
        <label class="form-label">Current EPF Balance (₹)</label>
        <input type="number" id="cOpening" class="form-input" placeholder="Opening balance" value="0">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group">
          <label class="form-label">Employee Rate (%)</label>
          <input type="number" id="cEeRate" class="form-input" value="12" step="0.1">
        </div>
        <div class="form-group">
          <label class="form-label">Employer EPF Rate (%)</label>
          <input type="number" id="cErRate" class="form-input" value="3.67" step="0.01">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">VPF Rate (% of basic, optional)</label>
        <input type="number" id="cVpf" class="form-input" value="0" step="0.5" placeholder="0">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group">
          <label class="form-label">Interest Rate (% p.a.)</label>
          <input type="number" id="cRate" class="form-input" value="8.25" step="0.01">
        </div>
        <div class="form-group">
          <label class="form-label">Annual Salary Growth (%)</label>
          <input type="number" id="cGrowth" class="form-input" value="5" step="0.5">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group">
          <label class="form-label">Current Age</label>
          <input type="number" id="cAge" class="form-input" value="30" min="18" max="57">
        </div>
        <div class="form-group">
          <label class="form-label">Retirement Age</label>
          <input type="number" id="cRetAge" class="form-input" value="58" min="50" max="70">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Projection Years (max = years to retire)</label>
        <input type="number" id="cYears" class="form-input" value="10" min="1" max="40">
      </div>

      <button class="btn btn-primary" style="width:100%;margin-top:6px;" onclick="runEpfCalc()">🧮 Calculate</button>
      <button class="btn btn-ghost" style="width:100%;margin-top:6px;font-size:12px;" onclick="prefillFromTracker()">📋 Prefill from Tracker</button>
    </div>

    <!-- Results Panel -->
    <div id="calcResults" style="display:none;">
      <!-- Summary Cards -->
      <div class="stats-grid" style="margin-bottom:16px;" id="calcSummary"></div>

      <!-- Tax Warning Banner -->
      <div id="taxWarning" style="display:none;background:rgba(234,88,12,.08);border:1px solid rgba(234,88,12,.3);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:var(--text-primary);">
        ⚠️ <strong>Tax Alert:</strong> Your annual EPF/VPF employee contribution exceeds ₹2.5 lakh.
        Per Budget 2021, interest on contributions above ₹2.5L is taxable as per your slab.
        <span id="taxWarningAmt"></span>
      </div>

      <!-- FY Table -->
      <div class="card">
        <div style="padding:14px 16px 8px;font-size:13px;font-weight:600;color:var(--text-primary);">📅 Year-wise Breakup (EPFO Monthly Running Balance Method)</div>
        <div class="table-responsive">
          <table class="table" style="font-size:12px;">
            <thead><tr>
              <th>FY</th>
              <th class="text-right">Basic/mo</th>
              <th class="text-right">Emp+VPF/mo</th>
              <th class="text-right">Employer/mo</th>
              <th class="text-right">Rate</th>
              <th class="text-right">Opening Bal</th>
              <th class="text-right">FY Interest</th>
              <th class="text-right">Closing Bal</th>
              <th class="text-center">Tax?</th>
            </tr></thead>
            <tbody id="calcFyBody"></tbody>
          </table>
        </div>
      </div>

      <!-- EPS Pension Note -->
      <div class="card mt-3" style="padding:14px;background:rgba(5,150,105,.04);border:1px solid rgba(5,150,105,.15);">
        <div style="font-size:12px;color:var(--text-muted);line-height:1.8;" id="epsNote"></div>
      </div>

      <!-- Methodology Note -->
      <div class="card mt-3" style="padding:14px;">
        <div style="font-size:11px;color:var(--text-muted);line-height:1.7;">
          <strong>Calculation Methodology:</strong>
          EPFO uses the <em>monthly running balance method</em> — contributions credited at month-end;
          interest = Σ(monthly running balances) × (rate ÷ 1200).
          Historical rates used where available (FY16–25); user-entered rate used for future years.
          <br>Tax rule: Interest on employee + VPF contributions exceeding ₹2.5 lakh/year is taxable (Budget 2021; ₹5L limit for govt employees).
          EPS pension = min(Basic, ₹15,000) × service_years ÷ 70 (EPFO formula).
        </div>
      </div>
    </div>

    <div id="calcPlaceholder" style="display:flex;align-items:center;justify-content:center;min-height:300px;color:var(--text-muted);font-size:14px;">
      Fill inputs and click Calculate to see your EPF projection →
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 3: GRATUITY TRACKER (t341)
     ══════════════════════════════════════════════════════════ -->
<div id="tabGratuity" style="display:none;">
  <div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card"><div class="stat-label">Total Records</div><div class="stat-value" id="gratCount">—</div></div>
    <div class="stat-card"><div class="stat-label">Total Gratuity Accrued</div><div class="stat-value text-success" id="gratTotal">—</div></div>
    <div class="stat-card"><div class="stat-label">Tax Exempt</div><div class="stat-value" id="gratExemptTotal">—</div></div>
    <div class="stat-card"><div class="stat-label">Active Employment</div><div class="stat-value" id="gratActive">—</div></div>
  </div>

  <div style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
    <select id="gratFilterPortfolio" class="form-select" style="width:170px;" onchange="loadGratuity()">
      <option value="">All Portfolios</option>
      <?php foreach ($portfolios as $p): ?>
      <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" onclick="openAddGrat()">+ Add Employment Record</button>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table">
        <thead><tr>
          <th>Employer</th>
          <th>Joining Date</th>
          <th class="text-right">Last Salary</th>
          <th class="text-right">Service</th>
          <th class="text-right">Gratuity (Act)</th>
          <th class="text-right">Tax Exempt</th>
          <th class="text-right">Taxable</th>
          <th>Status</th>
          <th class="text-center">Actions</th>
        </tr></thead>
        <tbody id="gratBody"><tr><td colspan="9" class="text-center" style="padding:40px;"><span class="spinner"></span></td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- Milestone Projection Card -->
  <div id="gratMilestone" style="display:none;" class="card mt-4">
    <div style="padding:14px 16px 8px;font-size:13px;font-weight:600;">📈 Gratuity Milestones — Years of Service</div>
    <div class="table-responsive">
      <table class="table" style="font-size:12px;">
        <thead><tr>
          <th>Service Years</th>
          <th class="text-right">Gratuity Amount</th>
          <th class="text-right">Tax Exempt</th>
          <th class="text-right">Taxable</th>
        </tr></thead>
        <tbody id="gratMilestoneBody"></tbody>
      </table>
    </div>
    <div style="padding:8px 16px 12px;font-size:11px;color:var(--text-muted);">
      Based on last salary of selected record. Formula: (Basic+DA ÷ 26) × 15 × Years (Payment of Gratuity Act 1972). Tax-free up to ₹20L (private sector).
    </div>
  </div>

  <!-- Info -->
  <div class="card mt-3" style="background:rgba(29,78,216,.04);border:1px solid rgba(29,78,216,.15);">
    <div style="padding:12px 14px;font-size:12px;color:var(--text-muted);line-height:1.8;">
      <strong>Gratuity Rules:</strong>
      Minimum 5 years service required (death/disability exempt) ·
      Formula: Last Basic+DA ÷ 26 × 15 × Years (Act) or ÷ 30 (non-Act) ·
      Tax-free up to <strong>₹20L</strong> for private sector · Fully exempt for govt employees ·
      Section 10(10), Income Tax Act
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 4: EPS PENSION CALCULATOR (t468)
     ══════════════════════════════════════════════════════════ -->
<div id="tabEps" style="display:none;">
  <div style="display:grid;grid-template-columns:400px 1fr;gap:20px;align-items:start;">

    <!-- Input Panel -->
    <div class="card" style="padding:18px;">
      <div style="font-size:14px;font-weight:600;margin-bottom:14px;color:var(--text-primary);">🏛️ EPS Pension Inputs</div>

      <div class="form-group">
        <label class="form-label">Basic + DA Salary (₹/month) *</label>
        <input type="number" id="epsBasic" class="form-input" placeholder="e.g. 50000" value="25000" oninput="epsUpdateNote()">
        <div id="epsCapNote" style="font-size:11px;color:var(--text-muted);margin-top:4px;"></div>
      </div>

      <div class="form-group">
        <label class="form-label">Total Pensionable Service (years) *</label>
        <input type="number" id="epsService" class="form-input" placeholder="e.g. 20" value="20" step="0.5" oninput="epsUpdateNote()">
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Minimum 10 years required for pension eligibility</div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group">
          <label class="form-label">Current Age</label>
          <input type="number" id="epsCurrentAge" class="form-input" value="40" min="20" max="60">
        </div>
        <div class="form-group">
          <label class="form-label">Pension Claim Age</label>
          <input type="number" id="epsClaimAge" class="form-input" value="58" min="50" max="60" oninput="epsUpdateNote()">
        </div>
      </div>
      <div style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">50–57: Early (−4%/yr) · 58: Normal · 59–60: Deferred (+4%/yr)</div>

      <div class="form-group">
        <label class="form-label">EPS Account Balance (₹) <span style="font-size:11px;color:var(--text-muted);">(for withdrawal calc if &lt;10 yrs)</span></label>
        <input type="number" id="epsBalance" class="form-input" placeholder="From passbook" value="0">
      </div>

      <div class="form-group">
        <label class="form-label">Projection Years</label>
        <input type="number" id="epsProjYears" class="form-input" value="20" min="5" max="40">
      </div>

      <!-- Options -->
      <div style="border:1px solid var(--border-color);border-radius:8px;padding:12px;margin-bottom:12px;">
        <div style="font-size:12px;font-weight:600;margin-bottom:8px;color:var(--text-muted);">OPTIONS</div>
        <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;font-size:13px;cursor:pointer;">
          <input type="checkbox" id="epsCommute" style="margin-top:2px;" onchange="epsToggleCommute()">
          <span>Commute 1/3rd of pension as lump sum (at age ≤58, reverts after 15 years)</span>
        </label>
        <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;font-size:13px;cursor:pointer;">
          <input type="checkbox" id="epsHigher" onchange="epsToggleHigher()">
          <span>Higher Pension Option (SC 2022 judgment — actual salary, no ₹15K cap)</span>
        </label>
        <div id="epsHigherSalRow" style="display:none;margin-top:6px;">
          <label class="form-label" style="font-size:12px;">Actual Salary for Higher Pension (₹/mo)</label>
          <input type="number" id="epsHigherSal" class="form-input" placeholder="Actual Basic+DA">
        </div>
        <label style="display:flex;align-items:flex-start;gap:8px;font-size:13px;cursor:pointer;">
          <input type="checkbox" id="epsPastService">
          <span>I have pre-Nov 1995 service</span>
        </label>
        <div id="epsPastSvcRow" style="display:none;margin-top:6px;">
          <label class="form-label" style="font-size:12px;">Pre-Nov 1995 Service (years)</label>
          <input type="number" id="epsPastYears" class="form-input" placeholder="Years" value="0">
        </div>
      </div>

      <button class="btn btn-primary" style="width:100%;margin-top:4px;" onclick="runEpsPensionCalc()">🏛️ Calculate EPS Pension</button>
      <button class="btn btn-ghost" style="width:100%;margin-top:6px;font-size:12px;" onclick="prefillEpsFromTracker()">📋 Prefill from EPF Tracker</button>
    </div>

    <!-- Results Panel -->
    <div>
      <div id="epsPlaceholder" style="display:flex;align-items:center;justify-content:center;min-height:320px;color:var(--text-muted);font-size:14px;">
        Fill inputs and click Calculate to see your EPS pension →
      </div>

      <div id="epsResults" style="display:none;">
        <!-- Summary Cards -->
        <div class="stats-grid" style="margin-bottom:16px;" id="epsSummaryCards"></div>

        <!-- Eligibility / Early-Deferred alert -->
        <div id="epsAdjAlert" style="display:none;margin-bottom:14px;border-radius:8px;padding:12px 16px;font-size:13px;"></div>

        <!-- Service Bonus alert -->
        <div id="epsBonusAlert" style="display:none;margin-bottom:14px;background:rgba(5,150,105,.07);border:1px solid rgba(5,150,105,.2);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--text-primary);">
          🎁 <strong>Service Bonus:</strong> You get +2 years bonus for completing ≥20 years of service!
        </div>

        <!-- Not eligible alert -->
        <div id="epsNotEligible" style="display:none;margin-bottom:14px;background:rgba(234,88,12,.08);border:1px solid rgba(234,88,12,.3);border-radius:8px;padding:12px 16px;font-size:13px;"></div>

        <!-- Higher pension comparison -->
        <div id="epsHigherPensionCard" style="display:none;" class="card" style="margin-bottom:16px;">
          <div style="padding:12px 14px 6px;font-size:13px;font-weight:600;color:var(--text-primary);">⚖️ Higher Pension Option — SC 2022 Analysis</div>
          <div id="epsHigherPensionBody" style="padding:0 14px 12px;font-size:13px;"></div>
        </div>

        <!-- Family Pension Card -->
        <div class="card" style="margin-bottom:16px;">
          <div style="padding:12px 14px 8px;font-size:13px;font-weight:600;color:var(--text-primary);">👨‍👩‍👧 Family Pension & Benefits</div>
          <div id="epsFamilyPensionBody" style="padding:0 14px 12px;"></div>
        </div>

        <!-- Projection Table -->
        <div class="card">
          <div style="padding:12px 14px 6px;font-size:13px;font-weight:600;color:var(--text-primary);">📅 Pension Projection</div>
          <div class="table-responsive">
            <table class="table" style="font-size:12px;">
              <thead><tr>
                <th>Period</th>
                <th class="text-right">Age</th>
                <th class="text-right">Monthly Pension</th>
                <th class="text-right">Annual Pension</th>
                <th class="text-right">Cumulative</th>
              </tr></thead>
              <tbody id="epsProjBody"></tbody>
            </table>
          </div>
        </div>

        <!-- Info box -->
        <div class="card mt-3" style="padding:14px;">
          <div style="font-size:11px;color:var(--text-muted);line-height:1.8;">
            <strong>EPS Rules Summary:</strong>
            Formula: Pensionable Salary × Pensionable Service ÷ 70 ·
            Pensionable Salary = min(Basic+DA, ₹15,000) ·
            Minimum 10 years for pension eligibility; else withdrawal ·
            Service bonus: +2 years if ≥20 years service ·
            Early pension (50–57): −4%/yr below 58 · Deferred (59–60): +4%/yr above 58 ·
            Min pension: ₹1,000/month (EPFO guarantee) ·
            Commutation: 1/3rd as lump sum (reverts after 15 years) ·
            Family pension: 50% of member pension (min ₹1,000) ·
            Higher Pension: Supreme Court Nov 2022 ruling allows contribution on actual salary if employer opts in.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     TAB 5: PPF + VPF + EPF COMBINED VIEW (t342)
     ══════════════════════════════════════════════════════════ -->
<div id="tabCombined" style="display:none;">

  <!-- Settings row -->
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
    <select id="cmbPortfolio" class="form-select" style="width:180px;" onchange="loadCombined()">
      <option value="">All Portfolios</option>
      <?php foreach ($portfolios as $p): ?>
      <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div style="display:flex;align-items:center;gap:8px;font-size:13px;">
      <label class="form-label" style="margin:0;white-space:nowrap;">VPF Rate (% of Basic)</label>
      <input type="number" id="cmbVpfRate" class="form-input" style="width:90px;" value="0" step="1" min="0" max="100" oninput="loadCombined()">
    </div>
    <div style="display:flex;align-items:center;gap:8px;font-size:13px;">
      <label class="form-label" style="margin:0;white-space:nowrap;">Projection Years</label>
      <input type="number" id="cmbProjYears" class="form-input" style="width:80px;" value="20" min="1" max="40" oninput="loadCombined()">
    </div>
    <div style="display:flex;align-items:center;gap:8px;font-size:13px;">
      <label class="form-label" style="margin:0;white-space:nowrap;">Expected Growth (%)</label>
      <input type="number" id="cmbGrowthRate" class="form-input" style="width:80px;" value="5" step="0.5" oninput="loadCombined()">
    </div>
    <button class="btn btn-primary btn-sm" onclick="loadCombined()">🔄 Refresh</button>
  </div>

  <!-- Loading -->
  <div id="cmbLoading" style="text-align:center;padding:60px;color:var(--text-muted);font-size:14px;display:none;"><span class="spinner"></span> Loading…</div>

  <!-- Empty state -->
  <div id="cmbEmpty" style="display:none;text-align:center;padding:60px;color:var(--text-muted);">
    <div style="font-size:40px;margin-bottom:12px;">🏦</div>
    <div style="font-size:16px;font-weight:600;margin-bottom:8px;">No data yet</div>
    <div style="font-size:13px;">Add EPF accounts (Tab 1) and/or PPF accounts (Post Office page) to see the combined view.</div>
  </div>

  <div id="cmbContent" style="display:none;">

    <!-- Summary Cards -->
    <div class="stats-grid" style="margin-bottom:20px;" id="cmbSummaryCards"></div>

    <!-- 80C tracker -->
    <div class="card" style="margin-bottom:20px;padding:16px;" id="cmbEightyC">
      <div style="font-size:13px;font-weight:600;margin-bottom:10px;color:var(--text-primary);">🎯 Section 80C Utilisation — FY <?php
        $fy = (date('n') >= 4) ? date('Y').'-'.(date('Y')+1) : (date('Y')-1).'-'.date('Y');
        echo $fy;
      ?></div>
      <div id="cmbEightyCBody"></div>
    </div>

    <!-- Holdings breakdown -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

      <!-- EPF Card -->
      <div class="card" style="padding:14px;">
        <div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:10px;">🏢 EPF Accounts</div>
        <div id="cmbEpfList" style="font-size:13px;"></div>
      </div>

      <!-- PPF Card -->
      <div class="card" style="padding:14px;">
        <div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:10px;">🛡️ PPF Accounts</div>
        <div id="cmbPpfList" style="font-size:13px;"></div>
      </div>
    </div>

    <!-- Projection Table -->
    <div class="card" style="margin-bottom:16px;">
      <div style="padding:12px 14px 6px;font-size:13px;font-weight:600;color:var(--text-primary);">
        📈 Combined Corpus Projection
        <span style="font-size:11px;color:var(--text-muted);font-weight:400;margin-left:8px;">EPF + VPF + PPF year-wise growth</span>
      </div>
      <div class="table-responsive">
        <table class="table" style="font-size:12px;">
          <thead><tr>
            <th>Year</th>
            <th class="text-right">EPF Balance</th>
            <th class="text-right">VPF Corpus</th>
            <th class="text-right">PPF Balance</th>
            <th class="text-right">Combined</th>
            <th class="text-right">Annual Contrib</th>
            <th class="text-right">Annual Interest</th>
          </tr></thead>
          <tbody id="cmbProjBody"></tbody>
        </table>
      </div>
    </div>

    <!-- Info box -->
    <div class="card" style="padding:14px;">
      <div style="font-size:11px;color:var(--text-muted);line-height:1.8;">
        <strong>Combined Retirement Corpus — Rules Summary:</strong>
        <strong>EPF:</strong> 8.25% p.a. (FY24-25), employee 12% + employer 3.67% + EPS 8.33% of Basic (capped ₹15K) ·
        <strong>VPF:</strong> Same rate as EPF (8.25%), additional voluntary contribution (0–100% of basic) — fully 80C eligible ·
        <strong>PPF:</strong> 7.1% p.a. (current), max ₹1.5L/year, tax-free corpus ·
        80C limit ₹1.5L/year (combined EPF employee share + VPF + PPF) ·
        EPF tax-free after 5 years service · PPF fully tax-free (EEE) · VPF = EEE (same as EPF)
      </div>
    </div>
  </div>
</div>

<!-- Add Gratuity Modal -->
<div class="modal-overlay" id="modalAddGrat" style="display:none;">
  <div class="modal" style="max-width:540px;">
    <div class="modal-header">
      <h3 class="modal-title" id="gratModalTitle">Add Employment Record</h3>
      <button class="modal-close" onclick="hideModal('modalAddGrat')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="gratEditId" value="">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Portfolio *</label>
          <select id="gratPPortfolio" class="form-select">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label">Employer Name *</label>
          <input type="text" id="gratPEmployer" class="form-input" placeholder="Company name"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Designation</label>
          <input type="text" id="gratPDesig" class="form-input" placeholder="e.g. Software Engineer"></div>
        <div class="form-group"><label class="form-label">Joining Date *</label>
          <input type="date" id="gratPJoining" class="form-input" onchange="updateGratPreview()"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Last Drawn Basic+DA (₹/mo) *</label>
          <input type="number" id="gratPSalary" class="form-input" placeholder="50000" oninput="updateGratPreview()"></div>
        <div class="form-group"><label class="form-label">Employment Status</label>
          <select id="gratPSepType" class="form-select" onchange="toggleSepDate()">
            <option value="employed">Currently Employed</option>
            <option value="resigned">Resigned</option>
            <option value="retired">Retired</option>
            <option value="terminated">Terminated</option>
            <option value="death">Death</option>
            <option value="disability">Disability</option>
          </select></div>
      </div>
      <div class="form-row" id="sepDateRow" style="display:none;">
        <div class="form-group"><label class="form-label">Separation Date</label>
          <input type="date" id="gratPSepDate" class="form-input" onchange="updateGratPreview()"></div>
        <div class="form-group"><label class="form-label">Actual Gratuity Received (₹)</label>
          <input type="number" id="gratPActual" class="form-input" placeholder="Leave blank if pending"></div>
      </div>
      <div class="form-row">
        <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:24px;">
          <input type="checkbox" id="gratPCovered" checked onchange="updateGratPreview()">
          <label for="gratPCovered" class="form-label" style="margin:0;">Covered by Gratuity Act 1972</label>
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:24px;">
          <input type="checkbox" id="gratPGovt" onchange="updateGratPreview()">
          <label for="gratPGovt" class="form-label" style="margin:0;">Government Employee</label>
        </div>
      </div>

      <!-- Live preview -->
      <div id="gratPreview" style="padding:10px 12px;background:var(--bg-secondary);border-radius:8px;font-size:12px;color:var(--text-muted);margin-bottom:8px;min-height:36px;"></div>

      <div class="form-group"><label class="form-label">Notes</label>
        <input type="text" id="gratPNotes" class="form-input" placeholder="Optional"></div>
      <div id="gratError" class="form-error" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modalAddGrat')">Cancel</button>
      <button class="btn btn-primary" onclick="saveGrat()">Save Record</button>
    </div>
  </div>
</div>

<!-- Add EPF Modal -->
<div class="modal-overlay" id="modalAddEpf" style="display:none;">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header"><h3 class="modal-title">Add EPF Account</h3><button class="modal-close" onclick="hideModal('modalAddEpf')">✕</button></div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Portfolio *</label>
          <select id="epfPPortfolio" class="form-select">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label">UAN (Universal Account No.)</label>
          <input type="text" id="epfPUan" class="form-input" placeholder="12-digit UAN"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Employer Name *</label>
          <input type="text" id="epfPEmployer" class="form-input" placeholder="Company name"></div>
        <div class="form-group"><label class="form-label">Basic Salary (₹/month) *</label>
          <input type="number" id="epfPBasic" class="form-input" placeholder="e.g. 50000" oninput="calcEpfContribs()"></div>
      </div>
      <div id="epfContribPreview" style="padding:8px;background:var(--bg-secondary);border-radius:7px;font-size:12px;color:var(--text-muted);margin-bottom:10px;"></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Current EPF Balance (₹)</label>
          <input type="number" id="epfPBal" class="form-input" placeholder="From passbook"></div>
        <div class="form-group"><label class="form-label">EPS Balance (₹)</label>
          <input type="number" id="epfPEps" class="form-input" placeholder="EPS corpus"></div>
      </div>
      <div class="form-group"><label class="form-label">Joining Date</label>
        <input type="date" id="epfPJoining" class="form-input"></div>
      <div id="epfError" class="form-error" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modalAddEpf')">Cancel</button>
      <button class="btn btn-primary" onclick="saveEpf()">Save EPF Account</button>
    </div>
  </div>
</div>

<script>
/* ─── Helpers ─────────────────────────────────────────────────────────────── */
let _epfData = [];
function fmtI(v) {
  v = Math.abs(parseFloat(v)||0);
  if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
  if (v >= 1e5) return '₹' + (v/1e5).toFixed(2) + 'L';
  return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
}
function fmtIFull(v) {
  return '₹' + (parseFloat(v)||0).toLocaleString('en-IN', {maximumFractionDigits:0});
}
function escH(t) {
  const d=document.createElement('div'); d.appendChild(document.createTextNode(t||'')); return d.innerHTML;
}

/* ─── Tab switching ───────────────────────────────────────────────────────── */
function switchEpfTab(tab) {
  document.getElementById('tabTracker').style.display  = tab==='tracker'  ? '' : 'none';
  document.getElementById('tabCalc').style.display     = tab==='calc'     ? '' : 'none';
  document.getElementById('tabGratuity').style.display = tab==='gratuity' ? '' : 'none';
  document.getElementById('tabEps').style.display      = tab==='eps'      ? '' : 'none';
  document.getElementById('tabCombined').style.display = tab==='combined' ? '' : 'none';
  document.getElementById('btnAddEpf').style.display   = tab==='tracker'  ? '' : 'none';
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  const ids = {tracker:'tabBtnTracker', calc:'tabBtnCalc', gratuity:'tabBtnGratuity', eps:'tabBtnEps', combined:'tabBtnCombined'};
  document.getElementById(ids[tab]).classList.add('active');
  if (tab === 'combined') loadCombined();
}

/* ─── Tracker ─────────────────────────────────────────────────────────────── */
async function loadEpf() {
  const pid = document.getElementById('epfFilterPortfolio')?.value || '';
  const res = await fetch(`${APP_URL}/api/router.php?action=epf_list&portfolio_id=${pid}`);
  const d = await res.json(); if (!d.success) return;
  _epfData = d.data || []; renderEpfTable(); updateEpfSummary();
}
function updateEpfSummary() {
  const bal    = _epfData.reduce((s,e)=>s+(parseFloat(e.current_balance)||0),0);
  const eps    = _epfData.reduce((s,e)=>s+(parseFloat(e.eps_balance)||0),0);
  const contrib= _epfData.reduce((s,e)=>s+(parseFloat(e.annual_contribution)||0),0);
  document.getElementById('epfCount').textContent   = _epfData.length;
  document.getElementById('epfBalance').textContent = fmtI(bal);
  document.getElementById('epfEps').textContent     = fmtI(eps);
  document.getElementById('epfContrib').textContent = fmtI(contrib)+'/yr';
}
function renderEpfTable() {
  const body = document.getElementById('epfBody');
  if (!_epfData.length) {
    body.innerHTML='<tr><td colspan="9" class="text-center" style="padding:48px;color:var(--text-muted);">No EPF accounts. Add your EPF details.</td></tr>';
    return;
  }
  body.innerHTML = _epfData.map(e=>`<tr>
    <td><div class="fund-name">${escH(e.employer_name)}</div>${e.uan?`<div class="fund-sub">UAN: ${escH(e.uan)}</div>`:''}</td>
    <td class="text-right">${fmtI(e.basic_salary)}/mo</td>
    <td class="text-right">${fmtI(e.employee_contribution)}/mo</td>
    <td class="text-right">${fmtI(e.employer_contribution)}/mo</td>
    <td class="text-right">${fmtI(e.eps_contribution)}/mo</td>
    <td class="text-right fw-600 text-success">${fmtI(e.current_balance)}</td>
    <td class="text-right">${fmtI(e.eps_balance)}</td>
    <td>${e.years_of_service ? e.years_of_service+' yrs' : '—'}</td>
    <td class="text-center" style="display:flex;gap:4px;justify-content:center;">
      <button class="btn btn-xs btn-ghost" onclick="updateEpfBal(${e.id},${e.current_balance},${e.eps_balance})" title="Update balance">₹</button>
      <button class="btn btn-xs btn-ghost" onclick="calcFromAccount(${e.id})" title="Interest Calc">🧮</button>
      <button class="btn btn-xs btn-ghost" onclick="deleteEpf(${e.id})" title="Delete">✕</button>
    </td>
  </tr>`).join('');
}
function calcEpfContribs() {
  const basic = parseFloat(document.getElementById('epfPBasic')?.value)||0;
  const el = document.getElementById('epfContribPreview');
  if (!basic||!el){if(el)el.innerHTML='';return;}
  const empEe=Math.round(basic*0.12),empEr=Math.round(basic*0.0367),eps=Math.round(basic*0.0833);
  el.innerHTML=`Employee: <strong>₹${empEe.toLocaleString('en-IN')}/mo</strong> · Employer EPF: <strong>₹${empEr.toLocaleString('en-IN')}/mo</strong> · EPS: <strong>₹${eps.toLocaleString('en-IN')}/mo</strong>`;
}
function openAddEpf(){showModal('modalAddEpf');}
async function saveEpf() {
  const payload={action:'epf_add',portfolio_id:document.getElementById('epfPPortfolio').value,uan:document.getElementById('epfPUan').value,employer_name:document.getElementById('epfPEmployer').value,basic_salary:document.getElementById('epfPBasic').value,current_balance:document.getElementById('epfPBal').value||0,eps_balance:document.getElementById('epfPEps').value||0,joining_date:document.getElementById('epfPJoining').value};
  if(!payload.employer_name){document.getElementById('epfError').textContent='Employer name required.';document.getElementById('epfError').style.display='';return;}
  const res=await apiPost(payload);
  if(res.success){hideModal('modalAddEpf');showToast('EPF account added!','success');loadEpf();}
  else{document.getElementById('epfError').textContent=res.message;document.getElementById('epfError').style.display='';}
}
async function updateEpfBal(id,curBal,curEps) {
  const bal=prompt('EPF Balance (₹):',curBal); if(!bal)return;
  const eps=prompt('EPS Balance (₹):',curEps)||curEps;
  await apiPost({action:'epf_update_balance',id,current_balance:bal,eps_balance:eps});
  showToast('Balance updated.','success'); loadEpf();
}
async function deleteEpf(id) {
  if(!confirm('Delete this EPF account?'))return;
  await apiPost({action:'epf_delete',id}); showToast('Deleted.','success'); loadEpf();
}

/* ─── Interest Calculator (t339) ─────────────────────────────────────────── */
async function runEpfCalc() {
  const payload = {
    action         : 'epf_interest_calc',
    basic_salary   : document.getElementById('cBasic').value,
    opening_balance: document.getElementById('cOpening').value,
    employee_rate  : document.getElementById('cEeRate').value,
    employer_rate  : document.getElementById('cErRate').value,
    vpf_rate       : document.getElementById('cVpf').value,
    interest_rate  : document.getElementById('cRate').value,
    basic_growth   : document.getElementById('cGrowth').value,
    current_age    : document.getElementById('cAge').value,
    retirement_age : document.getElementById('cRetAge').value,
    proj_years     : document.getElementById('cYears').value,
  };

  const btn = document.querySelector('#tabCalc .btn-primary');
  btn.textContent = '⏳ Calculating…'; btn.disabled = true;

  try {
    const res = await apiPost(payload);
    if (!res.success) { showToast(res.message || 'Error', 'error'); return; }
    renderCalcResults(res.data);
  } finally {
    btn.textContent = '🧮 Calculate'; btn.disabled = false;
  }
}

function renderCalcResults(data) {
  const s = data.summary;
  document.getElementById('calcPlaceholder').style.display = 'none';
  document.getElementById('calcResults').style.display     = '';

  // Summary cards
  document.getElementById('calcSummary').innerHTML = `
    <div class="stat-card">
      <div class="stat-label">Final Corpus</div>
      <div class="stat-value text-success">${fmtI(s.final_corpus)}</div>
      <div class="stat-sub">After ${s.proj_years} years</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Contributions</div>
      <div class="stat-value">${fmtI(s.total_contribution)}</div>
      <div class="stat-sub">Employee + Employer</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Interest Earned</div>
      <div class="stat-value text-warning">${fmtI(s.total_interest)}</div>
      <div class="stat-sub">${s.interest_pct}% of deployed capital</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">EPS Pension (est.)</div>
      <div class="stat-value">${fmtIFull(s.eps_monthly)}/mo</div>
      <div class="stat-sub">After ${s.proj_years} yrs service</div>
    </div>
  `;

  // Tax warning
  const txW = document.getElementById('taxWarning');
  if (s.has_tax_risk) {
    txW.style.display = '';
    document.getElementById('taxWarningAmt').textContent =
      ` Estimated taxable interest over projection: ${fmtIFull(s.total_taxable_intr)}.`;
  } else {
    txW.style.display = 'none';
  }

  // FY table
  const tbody = document.getElementById('calcFyBody');
  tbody.innerHTML = data.fy_data.map(row => `<tr>
    <td class="fw-600">${escH(row.fy)}</td>
    <td class="text-right">${fmtIFull(row.basic_salary)}</td>
    <td class="text-right">${fmtIFull(row.emp_ee_monthly + row.vpf_monthly)}${row.vpf_monthly>0?'<span class="badge badge-info" style="font-size:9px;margin-left:3px;">+VPF</span>':''}</td>
    <td class="text-right">${fmtIFull(row.emp_er_monthly)}</td>
    <td class="text-right">${row.interest_rate}%</td>
    <td class="text-right">${fmtI(row.opening_balance)}</td>
    <td class="text-right text-warning fw-600">${fmtI(row.fy_interest)}</td>
    <td class="text-right text-success fw-600">${fmtI(row.closing_balance)}</td>
    <td class="text-center">${row.tax_flag
      ? `<span style="color:#ea580c;font-size:11px;" title="Interest on excess ₹${fmtIFull(row.taxable_contrib)} taxable">⚠️ ₹${(row.taxable_interest/1000).toFixed(1)}K</span>`
      : '<span style="color:var(--text-muted);font-size:11px;">✅</span>'}</td>
  </tr>`).join('');

  // EPS note
  document.getElementById('epsNote').innerHTML =
    `<strong>EPS Pension Estimate:</strong> ₹${fmtIFull(s.eps_monthly)}/month after ${s.proj_years} years of service. ` +
    `Formula: min(Basic, ₹15,000) × service_years ÷ 70. ` +
    `Note: EPS is subject to minimum pension rules and EPFO circulars. Actual pension may differ. ` +
    `<br><strong>Years to retirement:</strong> ${s.years_to_retire} yrs · ` +
    `<strong>Projection covers:</strong> ${s.proj_years} yrs.`;
}

function prefillFromTracker() {
  if (!_epfData.length) {
    showToast('Load EPF Tracker first, then prefill.', 'warning');
    switchEpfTab('tracker');
    return;
  }
  // Use first active account
  const acc = _epfData[0];
  if (acc.basic_salary)     document.getElementById('cBasic').value   = acc.basic_salary;
  if (acc.current_balance)  document.getElementById('cOpening').value = acc.current_balance;
  showToast(`Prefilled from: ${acc.employer_name}`, 'success');
}

function calcFromAccount(id) {
  const acc = _epfData.find(e => e.id === id);
  if (!acc) return;
  if (acc.basic_salary)     document.getElementById('cBasic').value   = acc.basic_salary;
  if (acc.current_balance)  document.getElementById('cOpening').value = acc.current_balance;
  switchEpfTab('calc');
  showToast(`Loaded: ${acc.employer_name}`, 'success');
}

/* ─── Gratuity Tracker (t341) ─────────────────────────────────────────────── */
let _gratData = [];

async function loadGratuity() {
  const pid = document.getElementById('gratFilterPortfolio')?.value || '';
  const res = await fetch(`${APP_URL}/api/router.php?action=gratuity_list&portfolio_id=${pid}`);
  const d = await res.json(); if (!d.success) return;
  _gratData = d.data || [];
  renderGratTable();
  updateGratSummary();
}

function updateGratSummary() {
  const active  = _gratData.filter(g => g.separation_type === 'employed').length;
  const totGrat = _gratData.reduce((s,g) => s + (parseFloat(g.computed?.gratuity)||0), 0);
  const totExem = _gratData.reduce((s,g) => s + (parseFloat(g.computed?.tax_exempt)||0), 0);
  document.getElementById('gratCount').textContent       = _gratData.length;
  document.getElementById('gratActive').textContent      = active + ' active';
  document.getElementById('gratTotal').textContent       = fmtI(totGrat);
  document.getElementById('gratExemptTotal').textContent = fmtI(totExem);
}

function renderGratTable() {
  const body = document.getElementById('gratBody');
  if (!_gratData.length) {
    body.innerHTML = '<tr><td colspan="9" class="text-center" style="padding:48px;color:var(--text-muted);">No records. Add your employment history.</td></tr>';
    return;
  }
  body.innerHTML = _gratData.map((g, idx) => {
    const c = g.computed || {};
    const statusBadge = g.separation_type === 'employed'
      ? '<span class="badge badge-success">Active</span>'
      : `<span class="badge badge-info">${escH(g.separation_type)}</span>`;
    const serviceStr = `${g.years_of_service||0}y ${(g.months_of_service||0)%12}m`;
    return `<tr>
      <td>
        <div class="fund-name">${escH(g.employer_name)}</div>
        ${g.designation ? `<div class="fund-sub">${escH(g.designation)}</div>` : ''}
      </td>
      <td>${g.joining_date||'—'}</td>
      <td class="text-right">${fmtIFull(g.last_drawn_salary)}/mo</td>
      <td class="text-right">${serviceStr}</td>
      <td class="text-right fw-600 ${c.eligible ? 'text-success':'text-warning'}">${c.eligible ? fmtI(c.gratuity) : `<span title="${escH(c.note||'')}">Not eligible</span>`}</td>
      <td class="text-right">${c.eligible ? fmtI(c.tax_exempt) : '—'}</td>
      <td class="text-right ${(c.taxable||0)>0 ? 'text-danger':''}">${c.eligible ? ((c.taxable||0)>0 ? fmtI(c.taxable):'₹0') : '—'}</td>
      <td>${statusBadge}${g.actual_gratuity != null ? `<br><span style="font-size:11px;color:var(--text-muted);">Rcvd: ${fmtI(g.actual_gratuity)}</span>` : ''}</td>
      <td class="text-center" style="display:flex;gap:4px;justify-content:center;">
        <button class="btn btn-xs btn-ghost" onclick="showGratMilestones(${idx})" title="Milestones">📈</button>
        <button class="btn btn-xs btn-ghost" onclick="editGrat(${idx})" title="Edit">✎</button>
        <button class="btn btn-xs btn-ghost" onclick="deleteGrat(${g.id})" title="Delete">✕</button>
      </td>
    </tr>`;
  }).join('');
}

function showGratMilestones(idx) {
  const g = _gratData[idx];
  const milestones = g.computed?.milestones || [];
  const ms = document.getElementById('gratMilestone');
  document.getElementById('gratMilestoneBody').innerHTML = milestones.map(m => `<tr>
    <td class="fw-600">${m.years} yrs</td>
    <td class="text-right">${fmtIFull(m.gratuity)}</td>
    <td class="text-right text-success">${fmtIFull(m.exempt)}</td>
    <td class="text-right ${m.taxable>0?'text-danger':''}">${fmtIFull(m.taxable)}</td>
  </tr>`).join('');
  ms.style.display = '';
  ms.scrollIntoView({behavior:'smooth', block:'start'});
}

function openAddGrat() {
  document.getElementById('gratEditId').value='';
  document.getElementById('gratModalTitle').textContent='Add Employment Record';
  ['gratPEmployer','gratPDesig','gratPJoining','gratPSalary','gratPSepDate','gratPActual','gratPNotes'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('gratPSepType').value='employed';
  document.getElementById('gratPCovered').checked=true;
  document.getElementById('gratPGovt').checked=false;
  document.getElementById('gratError').style.display='none';
  document.getElementById('gratPreview').innerHTML='';
  toggleSepDate();
  showModal('modalAddGrat');
}

function editGrat(idx) {
  const g = _gratData[idx];
  document.getElementById('gratEditId').value=''+g.id;
  document.getElementById('gratModalTitle').textContent='Edit Employment Record';
  document.getElementById('gratPPortfolio').value=g.portfolio_id;
  document.getElementById('gratPEmployer').value=g.employer_name;
  document.getElementById('gratPDesig').value=g.designation||'';
  document.getElementById('gratPJoining').value=g.joining_date;
  document.getElementById('gratPSalary').value=g.last_drawn_salary;
  document.getElementById('gratPSepType').value=g.separation_type;
  document.getElementById('gratPSepDate').value=g.separation_date||'';
  document.getElementById('gratPActual').value=g.actual_gratuity!=null?g.actual_gratuity:'';
  document.getElementById('gratPCovered').checked=!!+g.is_covered_by_act;
  document.getElementById('gratPGovt').checked=!!+g.is_govt_employee;
  document.getElementById('gratPNotes').value=g.notes||'';
  document.getElementById('gratError').style.display='none';
  toggleSepDate();
  updateGratPreview();
  showModal('modalAddGrat');
}

function toggleSepDate() {
  const sep = document.getElementById('gratPSepType').value;
  document.getElementById('sepDateRow').style.display = sep!=='employed' ? '' : 'none';
}

function updateGratPreview() {
  const salary  = parseFloat(document.getElementById('gratPSalary').value)||0;
  const joining = document.getElementById('gratPJoining').value;
  const sepDate = document.getElementById('gratPSepDate').value;
  const covered = document.getElementById('gratPCovered').checked;
  const isGovt  = document.getElementById('gratPGovt').checked;
  const sepType = document.getElementById('gratPSepType').value;
  const el = document.getElementById('gratPreview');
  if (!salary||!joining){el.innerHTML='';return;}
  const from  = new Date(joining);
  const to    = sepDate ? new Date(sepDate) : new Date();
  const months= Math.max(0, Math.floor((to-from)/(1000*60*60*24*30.4375)));
  const yrs   = Math.floor(months/12);
  const rem   = months%12;
  const effYrs= yrs+(rem>=6?1:0);
  const divisor= covered?26:30;
  const deathDis=['death','disability'].includes(sepType);
  const eligible= deathDis||effYrs>=5;
  const grat  = eligible ? Math.round((salary/divisor)*15*effYrs) : 0;
  const exemptLimit= isGovt?Infinity:(covered?2000000:1000000);
  const exempt= Math.min(grat,exemptLimit);
  const taxable=Math.max(0,grat-exempt);
  el.innerHTML=`Service: <strong>${yrs}y ${rem}m</strong> (effective: ${effYrs}y) &nbsp;·&nbsp;
    Gratuity: <strong style="color:var(--accent)">${fmtI(grat)}</strong> &nbsp;·&nbsp;
    Exempt: <strong style="color:#16a34a">${fmtI(exempt)}</strong>
    ${taxable>0?` &nbsp;·&nbsp; Taxable: <strong style="color:#dc2626">${fmtI(taxable)}</strong>`:''}
    ${!eligible&&!deathDis?`<br><span style="color:#f59e0b">⚠️ Need 5 years for eligibility (currently ${yrs}y ${rem}m)</span>`:''}`;
}

async function saveGrat() {
  const id = document.getElementById('gratEditId').value;
  const payload = {
    action:'gratuity_'+(id?'update':'add'), id,
    portfolio_id:document.getElementById('gratPPortfolio').value,
    employer_name:document.getElementById('gratPEmployer').value,
    designation:document.getElementById('gratPDesig').value,
    joining_date:document.getElementById('gratPJoining').value,
    last_drawn_salary:document.getElementById('gratPSalary').value,
    separation_type:document.getElementById('gratPSepType').value,
    separation_date:document.getElementById('gratPSepDate').value,
    actual_gratuity:document.getElementById('gratPActual').value,
    is_covered_by_act:document.getElementById('gratPCovered').checked?1:0,
    is_govt_employee:document.getElementById('gratPGovt').checked?1:0,
    notes:document.getElementById('gratPNotes').value,
  };
  const errEl=document.getElementById('gratError');
  if(!payload.employer_name){errEl.textContent='Employer name required.';errEl.style.display='';return;}
  if(!payload.joining_date){errEl.textContent='Joining date required.';errEl.style.display='';return;}
  const res=await apiPost(payload);
  if(res.success){hideModal('modalAddGrat');showToast(id?'Record updated.':'Record added!','success');loadGratuity();}
  else{errEl.textContent=res.message;errEl.style.display='';}
}

async function deleteGrat(id){
  if(!confirm('Delete this gratuity record?'))return;
  await apiPost({action:'gratuity_delete',id});
  showToast('Deleted.','success');loadGratuity();
}

/* ─── EPS Pension Calculator (t468) ───────────────────────────────────────── */
function epsUpdateNote() {
  const basic = parseFloat(document.getElementById('epsBasic')?.value) || 0;
  const cap   = 15000;
  const note  = document.getElementById('epsCapNote');
  if (!note) return;
  if (basic > cap) {
    note.innerHTML = `⚠️ Salary >₹15,000 — EPS uses capped ₹15,000 (unless Higher Pension opted). <strong>Extra ₹${(basic-cap).toLocaleString('en-IN')}/mo ignored.</strong>`;
    note.style.color = '#f59e0b';
  } else {
    note.innerHTML = `EPS pensionable salary: <strong>₹${Math.min(basic,cap).toLocaleString('en-IN')}/mo</strong>`;
    note.style.color = '';
  }
}

function epsToggleHigher() {
  const checked = document.getElementById('epsHigher').checked;
  document.getElementById('epsHigherSalRow').style.display = checked ? '' : 'none';
}
function epsToggleCommute() {
  const age = parseInt(document.getElementById('epsClaimAge').value) || 58;
  if (document.getElementById('epsCommute').checked && age > 58) {
    showToast('Commutation is only available when claiming at age ≤58.', 'warning');
    document.getElementById('epsCommute').checked = false;
  }
}
document.getElementById('epsPastService')?.addEventListener?.('change', function() {
  document.getElementById('epsPastSvcRow').style.display = this.checked ? '' : 'none';
});

async function runEpsPensionCalc() {
  const payload = {
    action            : 'eps_pension_calc',
    basic_salary      : document.getElementById('epsBasic').value,
    service_years     : document.getElementById('epsService').value,
    current_age       : document.getElementById('epsCurrentAge').value,
    claim_age         : document.getElementById('epsClaimAge').value,
    eps_balance       : document.getElementById('epsBalance').value || 0,
    proj_years        : document.getElementById('epsProjYears').value,
    commute           : document.getElementById('epsCommute').checked ? 1 : 0,
    higher_pension    : document.getElementById('epsHigher').checked ? 1 : 0,
    higher_pension_salary : document.getElementById('epsHigherSal')?.value || 0,
    past_service      : document.getElementById('epsPastService')?.checked
                          ? (document.getElementById('epsPastYears')?.value || 0) : 0,
  };

  const btn = document.querySelector('#tabEps .btn-primary');
  btn.textContent = '⏳ Calculating…'; btn.disabled = true;
  try {
    const res = await apiPost(payload);
    if (!res.success) { showToast(res.message || 'Error', 'error'); return; }
    renderEpsResults(res.data);
  } finally {
    btn.textContent = '🏛️ Calculate EPS Pension'; btn.disabled = false;
  }
}

function renderEpsResults(data) {
  const s = data.summary;
  document.getElementById('epsPlaceholder').style.display = 'none';
  document.getElementById('epsResults').style.display     = '';

  // ── Summary cards ──
  const eligible = s.pension_eligible;
  document.getElementById('epsSummaryCards').innerHTML = eligible ? `
    <div class="stat-card">
      <div class="stat-label">Monthly Pension</div>
      <div class="stat-value text-success">${fmtIFull(s.residual_pension)}/mo</div>
      <div class="stat-sub">At age ${s.claim_age}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Base Pension</div>
      <div class="stat-value">${fmtIFull(s.base_pension)}/mo</div>
      <div class="stat-sub">Before age adjustment</div>
    </div>
    ${s.commuted_lump_sum > 0 ? `<div class="stat-card">
      <div class="stat-label">Commuted Lump Sum</div>
      <div class="stat-value text-warning">${fmtI(s.commuted_lump_sum)}</div>
      <div class="stat-sub">1/3rd × commutation factor${s.commute_breakeven_yrs ? ` · break-even ${s.commute_breakeven_yrs}y` : ''}</div>
    </div>` : ''}
    <div class="stat-card">
      <div class="stat-label">Pensionable Service</div>
      <div class="stat-value">${s.pensionable_service} yrs</div>
      <div class="stat-sub">${s.service_bonus > 0 ? `Includes +${s.service_bonus}yr bonus` : 'No bonus yet'}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Pensionable Salary</div>
      <div class="stat-value">₹${parseFloat(s.pensionable_salary).toLocaleString('en-IN')}/mo</div>
      <div class="stat-sub">${s.salary_capped ? '⚠️ Capped at ₹15K' : 'Full salary used'}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Family Pension</div>
      <div class="stat-value">${fmtIFull(s.family_pension)}/mo</div>
      <div class="stat-sub">50% · spouse / nominee</div>
    </div>
  ` : `
    <div class="stat-card" style="grid-column:1/-1;">
      <div class="stat-label">Not Pension Eligible</div>
      <div class="stat-value text-warning">EPS Withdrawal</div>
      <div class="stat-sub">Service < 10 years · Withdraw corpus</div>
    </div>
  `;

  // Service bonus alert
  document.getElementById('epsBonusAlert').style.display = s.service_bonus > 0 ? '' : 'none';

  // Age adjustment alert
  const adjAlert = document.getElementById('epsAdjAlert');
  if (eligible && s.age_adjustment_pct !== 0) {
    const isEarly = s.age_adjustment_pct < 0;
    adjAlert.style.display = '';
    adjAlert.style.background = isEarly ? 'rgba(234,88,12,.08)' : 'rgba(5,150,105,.07)';
    adjAlert.style.border     = isEarly ? '1px solid rgba(234,88,12,.3)' : '1px solid rgba(5,150,105,.2)';
    adjAlert.innerHTML = isEarly
      ? `⚠️ <strong>Early Pension Reduction:</strong> Claiming at age ${s.claim_age} (before 58) reduces pension by ${Math.abs(s.age_adjustment_pct)}%. Base ₹${fmtIFull(s.base_pension)}/mo → ₹${fmtIFull(s.adjusted_pension)}/mo.`
      : `✅ <strong>Deferred Pension Bonus:</strong> Claiming at age ${s.claim_age} (after 58) increases pension by +${s.age_adjustment_pct}%. Base ₹${fmtIFull(s.base_pension)}/mo → ₹${fmtIFull(s.adjusted_pension)}/mo.`;
  } else {
    adjAlert.style.display = 'none';
  }

  // Not eligible withdrawal info
  const notEl = document.getElementById('epsNotEligible');
  if (!eligible) {
    notEl.style.display = '';
    notEl.innerHTML = `⚠️ <strong>Pension requires ≥10 years service.</strong> With ${parseFloat(document.getElementById('epsService').value)||0} years, you can withdraw the EPS corpus.
    ${s.eps_withdrawal != null ? `<br>Estimated EPS withdrawal: <strong>${fmtI(s.eps_withdrawal)}</strong>` : ''}
    <br><span style="color:var(--text-muted);font-size:12px;">Alternatively, get a Scheme Certificate to carry service forward if rejoining an EPF-covered employer.</span>`;
  } else {
    notEl.style.display = 'none';
  }

  // Family pension
  document.getElementById('epsFamilyPensionBody').innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;padding-bottom:4px;">
      <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 12px;">
        <div style="font-size:11px;color:var(--text-muted);">Spouse / Widow Pension</div>
        <div style="font-size:16px;font-weight:700;color:var(--text-primary);">${fmtIFull(s.family_pension)}/mo</div>
        <div style="font-size:11px;color:var(--text-muted);">50% of member pension · min ₹1,000</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 12px;">
        <div style="font-size:11px;color:var(--text-muted);">Orphan Pension (Child)</div>
        <div style="font-size:16px;font-weight:700;color:var(--text-primary);">${fmtIFull(s.orphan_pension)}/mo</div>
        <div style="font-size:11px;color:var(--text-muted);">25% each · max 2 children · till 25</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 12px;">
        <div style="font-size:11px;color:var(--text-muted);">Disabled Member Pension</div>
        <div style="font-size:16px;font-weight:700;color:var(--text-primary);">${fmtIFull(s.disabled_pension)}/mo</div>
        <div style="font-size:11px;color:var(--text-muted);">75% of full pension (disability before retirement)</div>
      </div>
    </div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">
      Nominee must submit Form 10D within 30 days of member's death / retirement to EPFO Regional Office.
    </div>`;

  // Higher pension comparison
  const hpCard = document.getElementById('epsHigherPensionCard');
  const hpComp = data.higher_pension_comparison;
  if (hpComp) {
    hpCard.style.display = '';
    document.getElementById('epsHigherPensionBody').innerHTML = `
      <table style="width:100%;font-size:12px;border-collapse:collapse;">
        <tr style="border-bottom:1px solid var(--border-color);">
          <td style="padding:6px 0;color:var(--text-muted);">Normal EPS Pension (₹15K cap)</td>
          <td style="text-align:right;font-weight:600;">${fmtIFull(hpComp.normal_pension)}/mo</td>
        </tr>
        <tr style="border-bottom:1px solid var(--border-color);">
          <td style="padding:6px 0;color:var(--text-muted);">Higher Pension (actual salary)</td>
          <td style="text-align:right;font-weight:600;color:#16a34a;">${fmtIFull(hpComp.higher_pension)}/mo</td>
        </tr>
        <tr style="border-bottom:1px solid var(--border-color);">
          <td style="padding:6px 0;color:var(--text-muted);">Extra Monthly Pension</td>
          <td style="text-align:right;font-weight:700;color:var(--accent);">+${fmtIFull(hpComp.extra_monthly_pension)}/mo</td>
        </tr>
        <tr style="border-bottom:1px solid var(--border-color);">
          <td style="padding:6px 0;color:var(--text-muted);">Extra Annual EPS Contribution (8.33% on excess salary)</td>
          <td style="text-align:right;color:#dc2626;">${fmtI(hpComp.extra_annual_contrib)}/yr</td>
        </tr>
        ${hpComp.payback_years ? `<tr>
          <td style="padding:6px 0;color:var(--text-muted);">Break-even on extra contribution</td>
          <td style="text-align:right;">${hpComp.payback_years} years</td>
        </tr>` : ''}
      </table>
      <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">
        ⚖️ SC Judgment (Nov 2022): Higher pension is only available if both the employer and employee had opted for contribution on actual salary (not the ₹15K capped wage) before the cutoff date. Verify with your employer / EPFO.
      </div>`;
  } else {
    hpCard.style.display = 'none';
  }

  // Projection table
  const tbody = document.getElementById('epsProjBody');
  tbody.innerHTML = data.projection.map((row, i) => `<tr class="${i===0?'fw-600':''}">
    <td>${escH(row.year)}</td>
    <td class="text-right">${row.age}</td>
    <td class="text-right text-success fw-600">${fmtIFull(row.monthly_pension)}</td>
    <td class="text-right">${fmtI(row.annual_pension)}</td>
    <td class="text-right">${fmtI(row.cumulative)}</td>
  </tr>`).join('');
}

function prefillEpsFromTracker() {
  if (!_epfData.length) {
    showToast('Load EPF Tracker first, then prefill.', 'warning');
    switchEpfTab('tracker');
    return;
  }
  const acc = _epfData[0];
  if (acc.basic_salary)      document.getElementById('epsBasic').value   = acc.basic_salary;
  if (acc.eps_balance)       document.getElementById('epsBalance').value = acc.eps_balance;
  if (acc.years_of_service)  document.getElementById('epsService').value = acc.years_of_service;
  epsUpdateNote();
  showToast(`Prefilled from: ${acc.employer_name}`, 'success');
}

/* ─── PPF + VPF + EPF Combined View (t342) ───────────────────────────────── */
let _cmbDebounce = null;

async function loadCombined() {
  clearTimeout(_cmbDebounce);
  _cmbDebounce = setTimeout(_doLoadCombined, 300);
}

async function _doLoadCombined() {
  document.getElementById('cmbLoading').style.display  = '';
  document.getElementById('cmbContent').style.display  = 'none';
  document.getElementById('cmbEmpty').style.display    = 'none';

  const payload = {
    action      : 'retirement_combined',
    portfolio_id: document.getElementById('cmbPortfolio')?.value || '',
    vpf_rate    : parseFloat(document.getElementById('cmbVpfRate')?.value) || 0,
    proj_years  : parseInt(document.getElementById('cmbProjYears')?.value) || 20,
    growth_rate : parseFloat(document.getElementById('cmbGrowthRate')?.value) || 5,
  };

  const res = await apiPost(payload);
  document.getElementById('cmbLoading').style.display = 'none';

  if (!res.success) { showToast(res.message || 'Error loading combined view', 'error'); return; }
  const d = res.data;

  if (!d.has_data) {
    document.getElementById('cmbEmpty').style.display = '';
    return;
  }

  document.getElementById('cmbContent').style.display = '';
  renderCombined(d);
}

function renderCombined(d) {
  const s = d.summary;

  // ── Summary cards ──
  document.getElementById('cmbSummaryCards').innerHTML = `
    <div class="stat-card">
      <div class="stat-label">Total Corpus</div>
      <div class="stat-value text-success">${fmtI(s.total_corpus)}</div>
      <div class="stat-sub">EPF + VPF + PPF</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">EPF Corpus</div>
      <div class="stat-value">${fmtI(s.epf_corpus)}</div>
      <div class="stat-sub">${s.epf_accounts} account${s.epf_accounts!==1?'s':''}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">PPF Corpus</div>
      <div class="stat-value">${fmtI(s.ppf_corpus)}</div>
      <div class="stat-sub">${s.ppf_accounts} account${s.ppf_accounts!==1?'s':''}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Monthly Contribution</div>
      <div class="stat-value">${fmtI(s.monthly_contrib)}/mo</div>
      <div class="stat-sub">EPF+VPF (emp) + PPF est.</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Annual 80C Eligible</div>
      <div class="stat-value ${s.ec80_annual > 150000 ? 'text-warning' : 'text-success'}">${fmtI(s.ec80_annual)}</div>
      <div class="stat-sub">Limit ₹1.5L · ${s.ec80_annual > 150000 ? 'Over limit' : `${fmtI(Math.max(0,150000-s.ec80_annual))} remaining`}</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Projected Corpus (${s.proj_years}Y)</div>
      <div class="stat-value text-warning">${fmtI(s.projected_corpus)}</div>
      <div class="stat-sub">At current contribution rates</div>
    </div>`;

  // ── 80C Utilisation ──
  const limit = 150000;
  const used  = Math.min(s.ec80_annual, limit);
  const pct   = Math.min(100, Math.round(used / limit * 100));
  const color = pct >= 100 ? '#16a34a' : pct >= 70 ? '#f59e0b' : '#dc2626';

  document.getElementById('cmbEightyCBody').innerHTML = `
    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;">
      <span>${fmtI(used)} used of ₹1,50,000</span>
      <span style="color:${color};font-weight:700;">${pct}% utilised</span>
    </div>
    <div style="background:var(--bg-secondary);border-radius:6px;height:10px;overflow:hidden;margin-bottom:10px;">
      <div style="width:${pct}%;height:100%;background:${color};border-radius:6px;transition:width 0.5s;"></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px;">
      <div style="background:var(--bg-secondary);border-radius:7px;padding:8px 10px;">
        <div style="font-size:11px;color:var(--text-muted);">EPF (Employee 12%)</div>
        <div style="font-size:14px;font-weight:700;">${fmtI(s.epf_employee_annual)}/yr</div>
      </div>
      ${s.vpf_annual > 0 ? `<div style="background:var(--bg-secondary);border-radius:7px;padding:8px 10px;">
        <div style="font-size:11px;color:var(--text-muted);">VPF (Voluntary)</div>
        <div style="font-size:14px;font-weight:700;">${fmtI(s.vpf_annual)}/yr</div>
      </div>` : ''}
      <div style="background:var(--bg-secondary);border-radius:7px;padding:8px 10px;">
        <div style="font-size:11px;color:var(--text-muted);">PPF (est. annual)</div>
        <div style="font-size:14px;font-weight:700;">${fmtI(s.ppf_annual)}/yr</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:7px;padding:8px 10px;">
        <div style="font-size:11px;color:var(--text-muted);">Remaining 80C</div>
        <div style="font-size:14px;font-weight:700;color:${s.ec80_remaining > 0 ? '#2563eb' : '#6b7280'};">${fmtI(Math.max(0, s.ec80_remaining))}</div>
      </div>
    </div>
    ${s.ec80_annual > limit ? `<div style="margin-top:10px;font-size:12px;color:#f59e0b;padding:8px 10px;background:rgba(245,158,11,.08);border-radius:7px;">
      ⚠️ Your EPF+VPF+PPF contributions (${fmtI(s.ec80_annual)}/yr) exceed the ₹1.5L 80C limit by ${fmtI(s.ec80_annual-limit)}.
      The excess is not additionally deductible but still grows tax-free.
    </div>` : ''}`;

  // ── EPF list ──
  if (d.epf_accounts?.length) {
    document.getElementById('cmbEpfList').innerHTML = d.epf_accounts.map(e => `
      <div style="padding:8px 0;border-bottom:1px solid var(--border-color);">
        <div style="font-weight:600;">${escH(e.employer_name)}</div>
        <div style="font-size:12px;color:var(--text-muted);">Balance: <strong style="color:var(--text-primary);">${fmtI(e.current_balance)}</strong>
          · Emp: ${fmtI(e.employee_contribution)}/mo · Employer: ${fmtI(e.employer_contribution)}/mo</div>
        ${e.vpf_monthly > 0 ? `<div style="font-size:12px;color:#2563eb;">VPF: ${fmtI(e.vpf_monthly)}/mo</div>` : ''}
      </div>`).join('');
  } else {
    document.getElementById('cmbEpfList').innerHTML = '<div style="color:var(--text-muted);font-size:12px;">No EPF accounts. Add from Tab 1.</div>';
  }

  // ── PPF list ──
  if (d.ppf_accounts?.length) {
    document.getElementById('cmbPpfList').innerHTML = d.ppf_accounts.map(p => `
      <div style="padding:8px 0;border-bottom:1px solid var(--border-color);">
        <div style="font-weight:600;">${escH(p.holder_name || 'PPF Account')}</div>
        <div style="font-size:12px;color:var(--text-muted);">Balance: <strong style="color:var(--text-primary);">${fmtI(p.current_value || p.principal_amount)}</strong>
          · Rate: ${p.interest_rate}% · ${p.status === 'active' ? '✅ Active' : escH(p.status)}</div>
        ${p.annual_deposit ? `<div style="font-size:12px;color:var(--text-muted);">Annual deposit: ${fmtI(p.annual_deposit)}</div>` : ''}
      </div>`).join('');
  } else {
    document.getElementById('cmbPpfList').innerHTML = '<div style="color:var(--text-muted);font-size:12px;">No PPF accounts. Add from Post Office page.</div>';
  }

  // ── Projection table ──
  const tbody = document.getElementById('cmbProjBody');
  tbody.innerHTML = (d.projection || []).map((row, i) => `<tr class="${i===0?'fw-600':''}">
    <td>${escH(row.label)}</td>
    <td class="text-right">${fmtI(row.epf_balance)}</td>
    <td class="text-right">${row.vpf_balance > 0 ? fmtI(row.vpf_balance) : '<span style="color:var(--text-muted);">—</span>'}</td>
    <td class="text-right">${fmtI(row.ppf_balance)}</td>
    <td class="text-right text-success fw-600">${fmtI(row.combined)}</td>
    <td class="text-right">${fmtI(row.annual_contrib)}</td>
    <td class="text-right text-warning">${fmtI(row.annual_interest)}</td>
  </tr>`).join('');
}


  loadEpf(); loadGratuity(); epsUpdateNote();
  document.getElementById('epsPastService')?.addEventListener('change', function() {
    document.getElementById('epsPastSvcRow').style.display = this.checked ? '' : 'none';
  });
});
</script>
<?php $pageContent = ob_get_clean(); require_once APP_ROOT . '/templates/layout.php'; ?>
