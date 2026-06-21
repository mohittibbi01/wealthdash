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

<!-- t273: NPS Auto-Allocation Analyzer Card -->
<div class="card" style="margin-top:20px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <h3 class="card-title" style="margin:0;">🎯 Auto-Allocation Analyzer</h3>
      <p style="margin:4px 0 0;font-size:13px;color:var(--text-secondary);">Are you in the right asset class for your age? Compare vs PFRDA Lifecycle Funds (LC-25/50/75)</p>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
      <label style="font-size:13px;color:var(--text-secondary);">Your Age:</label>
      <input type="number" id="npsAllocAge" value="35" min="18" max="60"
             style="width:72px;padding:6px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg-secondary);color:var(--text);font-size:13px;"
             oninput="loadNpsAllocAnalyzer()">
      <button onclick="loadNpsAllocAnalyzer()" class="btn btn-sm btn-ghost">↻ Analyse</button>
    </div>
  </div>
  <div class="card-body" id="npsAllocAnalyzerWrap" style="min-height:80px;">
    <div style="text-align:center;padding:24px;color:var(--text-secondary);font-size:13px;">
      Enter your age above and click <strong>Analyse</strong> to check if your NPS allocation matches PFRDA recommendations.
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     t426 — NPS Allocation Optimizer
     Age-based ideal E/C/G/A split with expected return preview
     ═══════════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <h3 class="card-title" style="margin:0;">⚡ NPS Allocation Optimizer — t426</h3>
      <p style="margin:4px 0 0;font-size:13px;color:var(--text-secondary);">Ideal E/C/G/A allocation for your age — with return & risk simulation</p>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">Your Age</label>
        <input type="number" id="t426Age" value="35" min="18" max="70" style="width:72px;padding:6px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg-secondary);color:var(--text);font-size:13px;" oninput="t426Optimize()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:3px;">Risk Profile</label>
        <select id="t426Risk" style="padding:6px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg-secondary);color:var(--text);font-size:12px;" onchange="t426Optimize()">
          <option value="aggressive">Aggressive (LC-75)</option>
          <option value="moderate" selected>Moderate (LC-50)</option>
          <option value="conservative">Conservative (LC-25)</option>
          <option value="custom">Custom</option>
        </select>
      </div>
      <button onclick="t426Optimize()" class="btn btn-primary btn-sm" style="margin-top:16px;">Optimize →</button>
    </div>
  </div>
  <div class="card-body" style="padding:16px;">

    <!-- Custom sliders (shown only in custom mode) -->
    <div id="t426CustomSliders" style="display:none;background:var(--bg-secondary);border-radius:10px;padding:14px;margin-bottom:16px;">
      <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px;">Custom Allocation (E+C+G+A must = 100%)</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;">
        <?php foreach(['E'=>['Equity','#3b82f6'],'C'=>['Corporate','#10b981'],'G'=>['Govt Bonds','#8b5cf6'],'A'=>['Alternate','#f59e0b']] as $cls=>[$lbl,$col]): ?>
        <div>
          <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:600;margin-bottom:4px;">
            <span><?= $lbl ?> (<?= $cls ?>)</span>
            <span id="t426<?= $cls ?>Val" style="color:<?= $col ?>;font-weight:800;">25%</span>
          </div>
          <input type="range" id="t426<?= $cls ?>Range" min="0" max="<?= $cls==='A'?5:100 ?>" value="25" step="1"
                 style="width:100%;accent-color:<?= $col ?>;" oninput="t426SliderChange('<?= $cls ?>')">
        </div>
        <?php endforeach; ?>
      </div>
      <div id="t426SliderTotal" style="font-size:12px;margin-top:8px;padding:6px 10px;border-radius:6px;background:var(--bg-card);"></div>
    </div>

    <!-- Result area -->
    <div id="t426Result">
      <div style="text-align:center;padding:24px;color:var(--text-secondary);">Click <strong>Optimize →</strong> to see your ideal NPS allocation.</div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     t427 — NPS Pension Estimator
     Detailed monthly pension at 60 with annuity scenarios & chart
     ═══════════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;">
  <div class="card-header">
    <h3 class="card-title">🧓 NPS Pension Estimator — t427</h3>
    <span style="font-size:11px;color:var(--text-muted);">Detailed monthly pension at 60 — annuity scenarios, corpus growth, bucket strategy</span>
  </div>
  <div class="card-body" style="padding:16px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Current Age</label>
        <input type="number" id="t427Age" value="35" min="18" max="60" class="form-input" oninput="t427Estimate()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Retirement Age</label>
        <input type="number" id="t427RetAge" value="60" min="40" max="70" class="form-input" oninput="t427Estimate()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Monthly Contribution (₹)</label>
        <input type="number" id="t427Contrib" value="10000" min="500" class="form-input" oninput="t427Estimate()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Current NPS Corpus (₹)</label>
        <input type="number" id="t427Corpus" value="0" min="0" class="form-input" placeholder="0" oninput="t427Estimate()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Expected Return (% p.a.)</label>
        <select id="t427Return" class="form-input" onchange="t427Estimate()">
          <option value="8">8% — Conservative</option>
          <option value="10" selected>10% — Moderate</option>
          <option value="12">12% — Aggressive</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Annual SIP Step-Up (%)</label>
        <select id="t427Stepup" class="form-input" onchange="t427Estimate()">
          <option value="0">No step-up</option>
          <option value="5" selected>5% per year</option>
          <option value="10">10% per year</option>
        </select>
      </div>
    </div>
    <div id="t427Result"></div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     t428 — NPS vs EPF Comparison
     Side-by-side retirement corpus, tax, flexibility & pension comparison
     ═══════════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;">
  <div class="card-header">
    <h3 class="card-title">⚖️ NPS vs EPF — Retirement Comparison — t428</h3>
    <span style="font-size:11px;color:var(--text-muted);">Which retirement vehicle builds more wealth? Side-by-side corpus, tax & pension analysis</span>
  </div>
  <div class="card-body" style="padding:16px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:16px;">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Current Age</label>
        <input type="number" id="t428Age" value="30" min="18" max="55" class="form-input" oninput="t428Calc()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Retirement Age</label>
        <input type="number" id="t428RetAge" value="60" min="50" max="70" class="form-input" oninput="t428Calc()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Monthly Salary (₹)</label>
        <input type="number" id="t428Salary" value="50000" min="10000" class="form-input" oninput="t428Calc()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">NPS Contrib/mo (₹)</label>
        <input type="number" id="t428NpsContrib" value="5000" min="500" class="form-input" oninput="t428Calc()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Tax Slab (%)</label>
        <select id="t428TaxSlab" class="form-input" onchange="t428Calc()">
          <option value="5">5%</option>
          <option value="10">10%</option>
          <option value="20">20%</option>
          <option value="30" selected>30%</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">NPS Return (% p.a.)</label>
        <select id="t428NpsReturn" class="form-input" onchange="t428Calc()">
          <option value="8">8% Conservative</option>
          <option value="10" selected>10% Moderate</option>
          <option value="12">12% Aggressive</option>
        </select>
      </div>
    </div>
    <div id="t428Result"></div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     t429 — NPS Withdrawal Rules
     Interactive guide: partial, premature & maturity withdrawal rules
     ═══════════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;">
  <div class="card-header">
    <h3 class="card-title">📋 NPS Withdrawal Rules — t429</h3>
    <span style="font-size:11px;color:var(--text-muted);">Partial, premature & maturity withdrawal rules — when, how much, and tax impact</span>
  </div>
  <div class="card-body" style="padding:16px;">
    <!-- Tabs -->
    <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;">
      <?php foreach([['maturity','🎯 At Maturity (60+)'],['premature','⚡ Premature Exit'],['partial','📤 Partial Withdrawal'],['death','💔 On Death']] as [$k,$l]): ?>
      <button onclick="t429Tab('<?= $k ?>')" id="t429Tab_<?= $k ?>"
        style="padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;border:1.5px solid var(--border);background:<?= $k==='maturity'?'var(--primary)':'var(--bg-secondary)' ?>;color:<?= $k==='maturity'?'#fff':'var(--text)' ?>;cursor:pointer;transition:all .15s;"><?= $l ?></button>
      <?php endforeach; ?>
    </div>

    <!-- Maturity -->
    <div id="t429Panel_maturity">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;">
          <div style="font-size:13px;font-weight:800;color:#3b82f6;margin-bottom:10px;">Tier I — Maturity at 60</div>
          <?php foreach([
            ['60% Lump Sum', 'Tax-FREE. Withdraw up to 60% of corpus as lump sum.','#16a34a'],
            ['40% Annuity', 'Mandatory 40% must be used to buy annuity (pension plan).','#d97706'],
            ['Monthly Pension', 'Annuity pays a monthly pension for life — taxable as income.','#3b82f6'],
            ['Deferral Option', 'Can defer withdrawal up to age 75 — corpus keeps growing.','#8b5cf6'],
          ] as [$t,$d,$c]): ?>
          <div style="margin-bottom:10px;padding:10px;background:var(--bg-card);border-radius:8px;border-left:3px solid <?= $c ?>;">
            <div style="font-size:12px;font-weight:700;color:<?= $c ?>;margin-bottom:2px;"><?= $t ?></div>
            <div style="font-size:11px;color:var(--text-secondary);"><?= $d ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;">
          <div style="font-size:13px;font-weight:800;color:#10b981;margin-bottom:10px;">Tier II — Fully Flexible</div>
          <?php foreach([
            ['Full Withdrawal', 'Withdraw entire corpus anytime — no lock-in restrictions.','#16a34a'],
            ['No Annuity Required', 'Tier II has no mandatory annuity purchase requirement.','#10b981'],
            ['Taxable as Income', 'All withdrawals are added to income and taxed per slab.','#ef4444'],
            ['No Tax Benefit', 'No 80C deduction (except Govt employees under NPS Tier II).','#d97706'],
          ] as [$t,$d,$c]): ?>
          <div style="margin-bottom:10px;padding:10px;background:var(--bg-card);border-radius:8px;border-left:3px solid <?= $c ?>;">
            <div style="font-size:12px;font-weight:700;color:<?= $c ?>;margin-bottom:2px;"><?= $t ?></div>
            <div style="font-size:11px;color:var(--text-secondary);"><?= $d ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="margin-top:12px;padding:10px 14px;background:#fef3c7;border-radius:8px;border-left:3px solid #d97706;">
        <div style="font-size:12px;font-weight:700;color:#92400e;">💡 Key Maturity Rule</div>
        <div style="font-size:11px;color:#78350f;margin-top:2px;">If corpus ≤ ₹5 lakh at 60, you can withdraw 100% as lump sum (annuity exempted).</div>
      </div>
    </div>

    <!-- Premature -->
    <div id="t429Panel_premature" style="display:none;">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;margin-bottom:14px;">
        <div style="font-size:13px;font-weight:800;color:#ef4444;margin-bottom:10px;">Premature Exit (Before Age 60) — Tier I</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <?php foreach([
            ['Eligibility','Must have 10+ years in NPS (i.e., joined before age 50)','#6b7280'],
            ['20% Lump Sum','Only 20% can be taken as lump sum (taxable)','#ef4444'],
            ['80% Annuity','80% must be used to buy an annuity plan — pension for life','#d97706'],
            ['Tax Impact','20% lump sum is taxable; annuity income is taxable as per slab','#ef4444'],
            ['Corpus < ₹2.5L','Full 100% withdrawal allowed — annuity not mandatory','#16a34a'],
            ['Alternative','Continue till 60 to maximize corpus and get 60% tax-free','#3b82f6'],
          ] as [$t,$d,$c]): ?>
          <div style="padding:10px;background:var(--bg-card);border-radius:8px;border-left:3px solid <?= $c ?>;">
            <div style="font-size:11px;font-weight:700;color:<?= $c ?>;margin-bottom:2px;"><?= $t ?></div>
            <div style="font-size:11px;color:var(--text-secondary);"><?= $d ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="padding:10px 14px;background:#fee2e2;border-radius:8px;border-left:3px solid #ef4444;">
        <div style="font-size:12px;font-weight:700;color:#991b1b;">⚠️ Premature Exit is Costly</div>
        <div style="font-size:11px;color:#7f1d1d;margin-top:2px;">You lose the 60% tax-free lump sum benefit and get only 20% as lump sum. Try to stay invested till 60 for maximum benefit.</div>
      </div>
    </div>

    <!-- Partial -->
    <div id="t429Panel_partial" style="display:none;">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;margin-bottom:14px;">
        <div style="font-size:13px;font-weight:800;color:#8b5cf6;margin-bottom:10px;">Partial Withdrawal — Tier I</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
          <?php foreach([
            ['Max Withdrawal','Up to 25% of your own contributions (not employer\'s)','#8b5cf6'],
            ['Min Tenure','Must have 3+ years in NPS before first withdrawal','#3b82f6'],
            ['Frequency','Max 3 partial withdrawals allowed in entire NPS tenure','#d97706'],
            ['Tax Treatment','Partial withdrawals are TAX-FREE (Section 10(12B) exemption)','#16a34a'],
          ] as [$t,$d,$c]): ?>
          <div style="padding:10px;background:var(--bg-card);border-radius:8px;border-left:3px solid <?= $c ?>;">
            <div style="font-size:11px;font-weight:700;color:<?= $c ?>;margin-bottom:2px;"><?= $t ?></div>
            <div style="font-size:11px;color:var(--text-secondary);"><?= $d ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:8px;">Allowed Reasons for Partial Withdrawal:</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;">
          <?php foreach(['Higher Education (self/children)','Marriage (self/children/siblings)','House Purchase/Construction','Medical Treatment (critical illness)','Disability (≥75%)','Skill Development / Startup (Govt approved)'] as $r): ?>
          <span style="padding:4px 10px;background:var(--bg-card);border:1px solid var(--border);border-radius:99px;font-size:11px;color:var(--text-secondary);"><?= $r ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Death -->
    <div id="t429Panel_death" style="display:none;">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;">
        <div style="font-size:13px;font-weight:800;color:#6b7280;margin-bottom:10px;">On Death of Subscriber</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <?php foreach([
            ['Before 60 — Tier I','100% corpus paid to nominee/legal heir. No annuity mandatory.','#16a34a'],
            ['After 60 — Tier I','Annuity stops (life annuity). Remaining lump sum to nominee.','#6b7280'],
            ['Tier II','100% corpus paid to nominee immediately.','#16a34a'],
            ['Tax on Death','Amount received by nominee is tax-free under Section 10(12A).','#16a34a'],
            ['Nominee Change','Update nominee via NPS CRA portal (eNPS) anytime.','#3b82f6'],
            ['Joint Life Annuity','Opt for joint life annuity so spouse continues getting pension.','#8b5cf6'],
          ] as [$t,$d,$c]): ?>
          <div style="padding:10px;background:var(--bg-card);border-radius:8px;border-left:3px solid <?= $c ?>;">
            <div style="font-size:11px;font-weight:700;color:<?= $c ?>;margin-bottom:2px;"><?= $t ?></div>
            <div style="font-size:11px;color:var(--text-secondary);"><?= $d ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     t430 — NPS Contribution Reminder
     Smart reminder setup: SIP date, monthly target, streak tracker
     ═══════════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <h3 class="card-title">🔔 NPS Contribution Reminder — t430</h3>
      <span style="font-size:11px;color:var(--text-muted);">Set your monthly contribution target, preferred SIP date & track your streak</span>
    </div>
    <button onclick="t430Save()" class="btn btn-primary btn-sm">💾 Save Reminder</button>
  </div>
  <div class="card-body" style="padding:16px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px;">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Monthly Target (₹)</label>
        <input type="number" id="t430Target" value="10000" min="500" class="form-input" oninput="t430Preview()">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Preferred SIP Date</label>
        <select id="t430SipDay" class="form-input" onchange="t430Preview()">
          <?php for($d=1;$d<=28;$d++): ?>
          <option value="<?=$d?>" <?=$d==5?'selected':''?>><?=$d?><?=($d==1?'st':($d==2?'nd':($d==3?'rd':'th')))?> of each month</option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Reminder Channel</label>
        <select id="t430Channel" class="form-input">
          <option value="browser">🖥️ Browser Notification</option>
          <option value="email">📧 Email</option>
          <option value="both">Both</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:4px;">Remind me (days before)</label>
        <select id="t430DaysBefore" class="form-input" onchange="t430Preview()">
          <option value="0">On the day</option>
          <option value="1" selected>1 day before</option>
          <option value="2">2 days before</option>
          <option value="3">3 days before</option>
        </select>
      </div>
    </div>

    <!-- Preview card -->
    <div id="t430Preview" style="background:linear-gradient(135deg,#1e40af,#3b82f6);border-radius:12px;padding:16px;color:#fff;margin-bottom:20px;">
      <div style="font-size:11px;font-weight:600;opacity:.8;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px;">Next Reminder</div>
      <div id="t430PreviewText" style="font-size:16px;font-weight:800;"></div>
      <div id="t430PreviewSub" style="font-size:12px;opacity:.8;margin-top:4px;"></div>
    </div>

    <!-- Annual projection -->
    <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;margin-bottom:16px;">
      <div style="font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px;">Annual Contribution Tracker</div>
      <div style="display:grid;grid-template-columns:repeat(12,1fr);gap:4px;" id="t430MonthGrid"></div>
      <div style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:11px;color:var(--text-secondary);">
        <span style="width:14px;height:14px;background:#16a34a;border-radius:3px;display:inline-block;"></span>Contributed
        <span style="width:14px;height:14px;background:#d97706;border-radius:3px;display:inline-block;margin-left:8px;"></span>Upcoming
        <span style="width:14px;height:14px;background:var(--border);border-radius:3px;display:inline-block;margin-left:8px;"></span>Missed
      </div>
    </div>

    <!-- 80CCD(1B) tracker -->
    <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;">
      <div style="font-size:12px;font-weight:700;color:var(--text-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px;">Tax Deduction Progress</div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;" id="t430TaxBars"></div>
    </div>
  </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════
// t426 — NPS Allocation Optimizer
// ═══════════════════════════════════════════════════════════════
(function(){
  const PROFILES = {
    aggressive:  { E:75, C:10, G:15, A:0, label:'LC-75 Aggressive',  color:'#ef4444', retDesc:'High growth, max equity. Best for age < 40.' },
    moderate:    { E:50, C:10, G:40, A:0, label:'LC-50 Moderate',    color:'#3b82f6', retDesc:'Balanced growth & stability. Best for age 35–50.' },
    conservative:{ E:25, C:10, G:65, A:0, label:'LC-25 Conservative', color:'#10b981', retDesc:'Capital preservation near retirement. Best for age 50+.' },
    custom:      null,
  };

  // Age-based equity reduction (PFRDA lifecycle)
  function ageAdjustedAlloc(baseE, age) {
    if (age <= 35) return baseE;
    const reduce = { aggressive:2.5, moderate:1.5, conservative:1.0 };
    const risk = document.getElementById('t426Risk')?.value || 'moderate';
    const r = reduce[risk] || 1.5;
    return Math.max(5, baseE - (age - 35) * r);
  }

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + ' Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + Math.round(v).toLocaleString('en-IN');
  }

  function blendReturn(eqPct, corpPct, govtPct, altPct) {
    // Approximate asset class returns
    const eqR = 12.5, cR = 8.5, gR = 7.5, aR = 9.0;
    return (eqPct*eqR + corpPct*cR + govtPct*gR + altPct*aR) / 100;
  }

  window.t426SliderChange = function(cls) {
    ['E','C','G','A'].forEach(c => {
      const v = parseInt(document.getElementById(`t426${c}Range`)?.value||0);
      document.getElementById(`t426${c}Val`).textContent = v + '%';
    });
    const total = ['E','C','G','A'].reduce((s,c)=>s+parseInt(document.getElementById(`t426${c}Range`)?.value||0),0);
    const tot = document.getElementById('t426SliderTotal');
    if (tot) {
      tot.innerHTML = `Total: <strong style="color:${total===100?'#16a34a':'#ef4444'}">${total}%</strong>${total===100?' ✅':' — must equal 100%'}`;
    }
    t426Optimize();
  };

  window.t426Optimize = function() {
    const age     = parseInt(document.getElementById('t426Age')?.value) || 35;
    const risk    = document.getElementById('t426Risk')?.value || 'moderate';
    const sliders = document.getElementById('t426CustomSliders');
    const result  = document.getElementById('t426Result');
    if (!result) return;

    // Show/hide custom sliders
    if (sliders) sliders.style.display = risk === 'custom' ? '' : 'none';

    let alloc;
    if (risk === 'custom') {
      const E = parseInt(document.getElementById('t426ERange')?.value||0);
      const C = parseInt(document.getElementById('t426CRange')?.value||0);
      const G = parseInt(document.getElementById('t426GRange')?.value||0);
      const A = parseInt(document.getElementById('t426ARange')?.value||0);
      const total = E+C+G+A;
      if (total !== 100) {
        result.innerHTML = `<div style="padding:14px;background:rgba(239,68,68,.08);border-radius:8px;color:#ef4444;font-size:13px;">⚠️ E+C+G+A = ${total}%. Must equal 100% to continue.</div>`;
        return;
      }
      alloc = { E, C, G, A, label:'Custom Allocation', color:'#6366f1', retDesc:'User-defined allocation.' };
    } else {
      const base = PROFILES[risk];
      const eqAdj = Math.round(ageAdjustedAlloc(base.E, age));
      const diff  = base.E - eqAdj;
      alloc = { E:eqAdj, C:base.C, G:Math.min(100, base.G+diff), A:base.A, label:base.label, color:base.color, retDesc:base.retDesc };
      // Normalize to 100
      const tot = alloc.E+alloc.C+alloc.G+alloc.A;
      if (tot !== 100) alloc.G += (100-tot);
    }

    const blendedReturn = blendReturn(alloc.E, alloc.C, alloc.G, alloc.A);
    const ageBucket = age < 35 ? 'Growth Phase' : age < 50 ? 'Accumulation Phase' : age < 55 ? 'Transition Phase' : 'Preservation Phase';

    // PFM recommendations based on asset class focus
    const pfmRec = alloc.E >= 50
      ? { pfm:'HDFC Pension / ICICI Pru Pension', reason:'Best 3Y/5Y equity class returns (17.4% / 16.1%)' }
      : alloc.G >= 60
      ? { pfm:'HDFC Pension / SBI Pension', reason:'Strong G-class performance, stable debt returns' }
      : { pfm:'HDFC Pension', reason:'Best overall across all asset classes consistently' };

    // Bars
    const barHtml = (label, pct, color, max) => `
      <div style="margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
          <span style="font-weight:600;">${label}</span>
          <span style="font-weight:800;color:${color};">${pct}%</span>
        </div>
        <div style="height:10px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
          <div style="height:100%;width:${Math.min(100,pct/max*100).toFixed(1)}%;background:${color};border-radius:99px;transition:width .4s;"></div>
        </div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">Max allowed: ${max}%</div>
      </div>`;

    // Expected return comparison
    const scenarios = [
      { label:'Your Blend', rate:blendedReturn.toFixed(1), color:alloc.color },
      { label:'100% Equity', rate:'12.5', color:'#ef4444' },
      { label:'100% Debt', rate:'7.8',  color:'#10b981' },
    ];
    const scenHtml = scenarios.map(s => `
      <div style="text-align:center;padding:10px;background:var(--bg-secondary);border-radius:8px;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">${s.label}</div>
        <div style="font-size:20px;font-weight:800;color:${s.color};">${s.rate}%</div>
        <div style="font-size:10px;color:var(--text-muted);">p.a. est.</div>
      </div>`).join('');

    // Phase badges
    const phaseBadge = (ph, active) => `<span style="padding:4px 10px;border-radius:99px;font-size:11px;font-weight:700;background:${active?'var(--accent)':'var(--bg-secondary)'};color:${active?'#fff':'var(--text-muted)'};">${ph}</span>`;

    result.innerHTML = `
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        <div style="padding:6px 14px;border-radius:99px;background:${alloc.color}22;border:1.5px solid ${alloc.color};color:${alloc.color};font-size:12px;font-weight:700;">${alloc.label}</div>
        <div style="display:flex;gap:6px;">
          ${phaseBadge('Growth <35', age<35)}
          ${phaseBadge('Accum 35–50', age>=35&&age<50)}
          ${phaseBadge('Transition 50–55', age>=50&&age<55)}
          ${phaseBadge('Preserve 55+', age>=55)}
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <!-- Allocation bars -->
        <div style="background:var(--bg-secondary);border-radius:10px;padding:16px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:12px;">Recommended Allocation</div>
          ${barHtml('Equity (E)', alloc.E, '#3b82f6', 75)}
          ${barHtml('Corporate Bonds (C)', alloc.C, '#10b981', 100)}
          ${barHtml('Govt Bonds (G)', alloc.G, '#8b5cf6', 100)}
          ${barHtml('Alternate Assets (A)', alloc.A, '#f59e0b', 5)}
        </div>

        <!-- Return simulation -->
        <div>
          <div style="background:var(--bg-secondary);border-radius:10px;padding:16px;margin-bottom:12px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:10px;">Expected Return (Blended)</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">${scenHtml}</div>
          </div>
          <!-- PFM recommendation -->
          <div style="background:rgba(99,102,241,.07);border:1px solid rgba(99,102,241,.2);border-radius:10px;padding:14px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6366f1;margin-bottom:6px;">🏆 Recommended PFM</div>
            <div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:4px;">${pfmRec.pfm}</div>
            <div style="font-size:12px;color:var(--text-secondary);">${pfmRec.reason}</div>
          </div>
        </div>
      </div>

      <!-- How to change allocation -->
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-secondary);margin-bottom:8px;">📋 How to Apply This Allocation</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;font-size:12px;">
          <div style="display:flex;gap:8px;align-items:flex-start;"><span style="color:#3b82f6;font-size:16px;">1</span><span>Login to <strong>CRA (NSDL/CDSL)</strong> portal — <em>npstrust.org.in</em></span></div>
          <div style="display:flex;gap:8px;align-items:flex-start;"><span style="color:#3b82f6;font-size:16px;">2</span><span>Go to <strong>Transact → Change Scheme Preference</strong></span></div>
          <div style="display:flex;gap:8px;align-items:flex-start;"><span style="color:#3b82f6;font-size:16px;">3</span><span>Select <strong>Active Choice</strong> and enter E:${alloc.E}% C:${alloc.C}% G:${alloc.G}% A:${alloc.A}%</span></div>
          <div style="display:flex;gap:8px;align-items:flex-start;"><span style="color:#3b82f6;font-size:16px;">4</span><span>1 free scheme change per year. Submit with OTP.</span></div>
        </div>
      </div>

      <div style="margin-top:10px;font-size:11px;color:var(--text-muted);padding:8px 12px;background:var(--bg-secondary);border-radius:6px;">
        ⚠️ <em>${alloc.retDesc} Returns are estimated. Actual NPS returns depend on market conditions and PFM performance. Consult your financial advisor.</em>
      </div>`;
  };

  // Auto-run on load
  document.addEventListener('DOMContentLoaded', function() { t426Optimize(); });
})();

// ═══════════════════════════════════════════════════════════════
// t427 — NPS Pension Estimator
// ═══════════════════════════════════════════════════════════════
(function(){
  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + ' Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + Math.round(v).toLocaleString('en-IN');
  }
  function fmtM(v) {
    return '₹' + Math.round(v).toLocaleString('en-IN') + '/mo';
  }

  window.t427Estimate = function() {
    const age     = parseInt(document.getElementById('t427Age')?.value)     || 35;
    const retAge  = parseInt(document.getElementById('t427RetAge')?.value)  || 60;
    const contrib = parseFloat(document.getElementById('t427Contrib')?.value)|| 10000;
    const corpus0 = parseFloat(document.getElementById('t427Corpus')?.value) || 0;
    const ratePA  = (parseFloat(document.getElementById('t427Return')?.value) || 10) / 100;
    const stepup  = (parseFloat(document.getElementById('t427Stepup')?.value) || 5)  / 100;
    const result  = document.getElementById('t427Result');
    if (!result || retAge <= age) return;

    const years = retAge - age;
    const r = ratePA / 12; // monthly rate

    // Step-up SIP corpus calculation
    let corpus = corpus0;
    let mc = contrib;
    for (let y = 0; y < years; y++) {
      corpus = corpus * Math.pow(1+r, 12);
      corpus += mc * ((Math.pow(1+r,12)-1)/r) * (1+r);
      mc *= (1+stepup);
    }

    const lumpsum = corpus * 0.60;
    const annuityCorpus = corpus * 0.40;
    const totalInvested = contrib * 12 * years * (stepup > 0 ? 1.3 : 1); // rough

    // Inflation adjusted
    const inflationFactor = Math.pow(1.06, years);
    const realCorpus = corpus / inflationFactor;

    // Annuity scenarios
    const annuityRates = [
      { rate:0.050, label:'5.0% — Conservative' },
      { rate:0.055, label:'5.5% — Typical PFRDA' },
      { rate:0.060, label:'6.0% — Optimistic' },
      { rate:0.065, label:'6.5% — Best case' },
    ];

    // 10-yr projection milestones (every 5 years)
    const milestones = [];
    let mc2 = contrib;
    let cp  = corpus0;
    for (let y = 0; y <= years; y++) {
      if (y % 5 === 0) {
        milestones.push({ year: age+y, corpus: Math.round(cp) });
      }
      cp = cp * Math.pow(1+r,12) + mc2*((Math.pow(1+r,12)-1)/r)*(1+r);
      mc2 *= (1+stepup);
    }

    // Bucket strategy for lumpsum
    const buckets = [
      { label:'Safety Bucket (0–2 yrs)', pct:10, color:'#10b981', inst:'FD / Liquid Fund',    rate:'6–7%' },
      { label:'Stable Bucket (2–7 yrs)',  pct:30, color:'#3b82f6', inst:'Debt MF / Bonds',    rate:'7–9%' },
      { label:'Growth Bucket (7+ yrs)',   pct:60, color:'#6366f1', inst:'Equity MF / Hybrid', rate:'10–12%' },
    ];

    // Annuity scenario cards
    const annHtml = annuityRates.map((a,i) => `
      <div style="padding:12px;background:${i===1?'rgba(99,102,241,.08)':'var(--bg-secondary)'};border-radius:10px;text-align:center;${i===1?'border:1.5px solid rgba(99,102,241,.25);':''}">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:5px;">${a.label}${i===1?' ⭐':''}</div>
        <div style="font-size:20px;font-weight:800;color:${i===1?'#6366f1':'var(--text-primary)'};">${fmtM(annuityCorpus*a.rate/12)}</div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:3px;">Monthly pension</div>
        <div style="font-size:10px;color:var(--text-muted);">In today's money: ${fmtM(annuityCorpus*a.rate/12/inflationFactor)}</div>
      </div>`).join('');

    // Milestone table
    const msHtml = milestones.map(m => `
      <tr>
        <td style="padding:6px 8px;font-size:12px;">Age ${m.year}</td>
        <td style="padding:6px 8px;font-size:12px;font-weight:700;text-align:right;">${fmtI(m.corpus)}</td>
        <td style="padding:6px 8px;">
          <div style="height:7px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;min-width:80px;">
            <div style="height:100%;width:${Math.min(100,(m.corpus/corpus*100)).toFixed(0)}%;background:#3b82f6;border-radius:99px;"></div>
          </div>
        </td>
      </tr>`).join('');

    // Bucket bars
    const bkHtml = buckets.map(b => `
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
        <div style="width:140px;font-size:12px;font-weight:600;">${b.label}</div>
        <div style="flex:1;height:28px;background:${b.color}20;border-radius:6px;overflow:hidden;display:flex;align-items:center;padding:0 10px;">
          <div style="font-size:11px;font-weight:700;color:${b.color};">${fmtI(lumpsum*b.pct/100)}</div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);width:120px;">${b.inst} @ ${b.rate}</div>
      </div>`).join('');

    // Sufficiency check
    const typicalPension = annuityCorpus * 0.055 / 12;
    const minSufficient  = 50000; // ₹50K/month considered comfortable
    const pensionRatio   = typicalPension / inflationFactor; // today's value
    const onTrack = pensionRatio >= minSufficient;

    result.innerHTML = `
      <!-- Key numbers -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;">
        <div style="background:rgba(59,130,246,.07);border-radius:10px;padding:14px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Total Corpus @ ${retAge}</div>
          <div style="font-size:22px;font-weight:800;color:#3b82f6;">${fmtI(corpus)}</div>
          <div style="font-size:11px;color:var(--text-muted);">${years} yrs · ${ratePA*100}% p.a.</div>
        </div>
        <div style="background:rgba(22,163,74,.07);border-radius:10px;padding:14px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Lump Sum (60%)</div>
          <div style="font-size:18px;font-weight:800;color:#16a34a;">${fmtI(lumpsum)}</div>
          <div style="font-size:11px;color:var(--text-muted);">Tax-free withdrawal</div>
        </div>
        <div style="background:rgba(139,92,246,.07);border-radius:10px;padding:14px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Annuity Corpus (40%)</div>
          <div style="font-size:18px;font-weight:800;color:#8b5cf6;">${fmtI(annuityCorpus)}</div>
          <div style="font-size:11px;color:var(--text-muted);">Mandatory annuity</div>
        </div>
        <div style="background:rgba(245,158,11,.07);border-radius:10px;padding:14px;text-align:center;">
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Real Value (6% inflation)</div>
          <div style="font-size:18px;font-weight:800;color:#d97706;">${fmtI(realCorpus)}</div>
          <div style="font-size:11px;color:var(--text-muted);">Today's purchasing power</div>
        </div>
      </div>

      <!-- Sufficiency banner -->
      <div style="margin-bottom:16px;padding:12px 16px;border-radius:10px;background:${onTrack?'rgba(22,163,74,.08)':'rgba(239,68,68,.08)'};border-left:4px solid ${onTrack?'#16a34a':'#ef4444'};">
        <div style="font-size:13px;font-weight:700;color:${onTrack?'#16a34a':'#ef4444'};">
          ${onTrack?'✅ On Track for a Comfortable Retirement':'⚠️ Pension May Fall Short of ₹50K/Month Target'}
        </div>
        <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;">
          ${onTrack
            ? `Estimated monthly pension of ${fmtM(typicalPension/inflationFactor)} in today's money exceeds ₹50K benchmark.`
            : `Today's equivalent pension ${fmtM(typicalPension/inflationFactor)} is below comfortable retirement benchmark. Consider increasing monthly contribution.`}
        </div>
      </div>

      <!-- Annuity scenarios -->
      <div style="margin-bottom:16px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Monthly Pension — Annuity Rate Scenarios</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">${annHtml}</div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:6px;">* 40% of corpus must be used for annuity at 60. Rates set by PFRDA-approved annuity providers.</div>
      </div>

      <!-- Corpus growth milestones -->
      <div style="margin-bottom:16px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">📈 Corpus Growth Milestones</div>
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;">
            <thead><tr style="background:var(--bg-secondary);">
              <th style="padding:6px 8px;font-size:11px;text-align:left;font-weight:700;color:var(--text-muted);">Age</th>
              <th style="padding:6px 8px;font-size:11px;text-align:right;font-weight:700;color:var(--text-muted);">Corpus</th>
              <th style="padding:6px 8px;font-size:11px;text-align:left;font-weight:700;color:var(--text-muted);">Progress</th>
            </tr></thead>
            <tbody>${msHtml}</tbody>
          </table>
        </div>
      </div>

      <!-- Lump sum bucket strategy -->
      <div style="margin-bottom:16px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">🪣 Lump Sum (60%) — Bucket Strategy</div>
        ${bkHtml}
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Bucket strategy: stagger lump sum across time horizons to manage longevity risk and maintain liquidity.</div>
      </div>

      <div style="font-size:11px;color:var(--text-muted);padding:10px 14px;background:var(--bg-secondary);border-radius:8px;">
        ⚠️ <em>Projections are illustrative. Actual NPS corpus and pension depend on market returns, contribution amounts, PFM performance, and annuity rates at the time of retirement. This is not financial advice.</em>
      </div>`;
  };

  document.addEventListener('DOMContentLoaded', function() { t427Estimate(); });
})();

// ═══════════════════════════════════════════════════════════════
// t428 — NPS vs EPF Comparison
// ═══════════════════════════════════════════════════════════════
(function(){
  const EPF_RATE   = 8.25; // Current EPF interest rate %
  const ANNUITY    = 6.0;  // Annuity rate for pension calc

  function fmtI(n){ return '₹' + Math.round(n).toLocaleString('en-IN'); }
  function fmtC(n){ return '₹' + (n/1e7).toFixed(2) + ' Cr'; }

  window.t428Calc = function(){
    const age        = parseInt(document.getElementById('t428Age')?.value)||30;
    const retAge     = parseInt(document.getElementById('t428RetAge')?.value)||60;
    const salary     = parseFloat(document.getElementById('t428Salary')?.value)||50000;
    const npsContrib = parseFloat(document.getElementById('t428NpsContrib')?.value)||5000;
    const slab       = parseFloat(document.getElementById('t428TaxSlab')?.value)||30;
    const npsRet     = parseFloat(document.getElementById('t428NpsReturn')?.value)||10;
    const years      = retAge - age;

    if(years<=0 || years>50) { document.getElementById('t428Result').innerHTML='<p style="color:#ef4444;font-size:12px;">Adjust ages.</p>'; return; }

    // EPF calculation
    const epfBasic       = salary * 0.5;
    const epfEmpContrib  = epfBasic * 0.12;
    const epfErContrib   = epfBasic * 0.0367; // 3.67% to EPF (8.33% goes to EPS)
    const epfMonthly     = epfEmpContrib + epfErContrib;
    const epfRate        = EPF_RATE/100/12;
    let epfCorpus        = 0;
    for(let m=0;m<years*12;m++) epfCorpus = (epfCorpus + epfMonthly)*(1+epfRate);
    epfCorpus = Math.round(epfCorpus);
    const epfLumpsum     = epfCorpus; // 100% tax-free on retirement
    const epfTaxSaved    = (epfEmpContrib*12) * (slab/100); // 80C benefit

    // NPS calculation
    const npsMonthlyRate = npsRet/100/12;
    let npsCorpus = 0;
    for(let m=0;m<years*12;m++) npsCorpus = (npsCorpus + npsContrib)*(1+npsMonthlyRate);
    npsCorpus = Math.round(npsCorpus);
    const npsLumpsum     = npsCorpus * 0.60; // 60% tax-free
    const npsAnnuityAmt  = npsCorpus * 0.40; // 40% annuity
    const npsPension     = (npsAnnuityAmt * ANNUITY/100)/12;
    const npsTaxSaved    = (npsContrib*12) * (slab/100); // 80CCD deduction

    // Tax comparison
    const epfTaxFreeCorpus = epfCorpus;
    const npsEffective     = npsLumpsum + (npsPension*12*20); // 20yr pension value approx

    const res = document.getElementById('t428Result');

    const isBetterNps = npsEffective > epfLumpsum;
    const verdictColor = isBetterNps ? '#3b82f6' : '#16a34a';
    const verdictText  = isBetterNps
      ? `🏆 NPS looks better for <strong>higher retirement income</strong> (assuming ${npsRet}% returns)`
      : `🏆 EPF looks better for <strong>guaranteed, stable corpus</strong> (${EPF_RATE}% fixed)`;

    res.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
        <!-- NPS Card -->
        <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border-radius:12px;padding:16px;border:2px solid #3b82f6;">
          <div style="font-size:12px;font-weight:800;color:#1d4ed8;margin-bottom:12px;letter-spacing:.3px;">🏛️ NPS (Tier I)</div>
          <div style="font-size:10px;color:#6b7280;margin-bottom:2px;">Your Contribution</div>
          <div style="font-size:18px;font-weight:800;color:#1d4ed8;">${fmtI(npsContrib)}<span style="font-size:11px;font-weight:500;">/mo</span></div>
          <hr style="border:none;border-top:1px solid #bfdbfe;margin:10px 0;">
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;"><span>Corpus @ ${retAge}</span><strong style="color:#1d4ed8;">${npsCorpus>1e7?fmtC(npsCorpus):fmtI(npsCorpus)}</strong></div>
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;"><span>Lump Sum (60%)</span><strong style="color:#16a34a;">${fmtI(npsLumpsum)} <small>(Tax-free)</small></strong></div>
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;"><span>Monthly Pension</span><strong style="color:#3b82f6;">${fmtI(npsPension)}/mo</strong></div>
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;"><span>Annual Tax Saved</span><strong style="color:#16a34a;">${fmtI(npsTaxSaved)}</strong></div>
          <div style="font-size:10px;color:#d97706;margin-top:8px;padding:6px 8px;background:#fef3c7;border-radius:6px;">⚠️ 40% mandatory annuity · Lock-in till ${retAge} · Pension is taxable</div>
        </div>
        <!-- EPF Card -->
        <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:12px;padding:16px;border:2px solid #16a34a;">
          <div style="font-size:12px;font-weight:800;color:#15803d;margin-bottom:12px;letter-spacing:.3px;">🏦 EPF</div>
          <div style="font-size:10px;color:#6b7280;margin-bottom:2px;">Total Monthly (Emp + Er)</div>
          <div style="font-size:18px;font-weight:800;color:#15803d;">${fmtI(epfMonthly)}<span style="font-size:11px;font-weight:500;">/mo</span></div>
          <hr style="border:none;border-top:1px solid #bbf7d0;margin:10px 0;">
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;"><span>Corpus @ ${retAge}</span><strong style="color:#15803d;">${epfCorpus>1e7?fmtC(epfCorpus):fmtI(epfCorpus)}</strong></div>
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;"><span>Lump Sum (100%)</span><strong style="color:#16a34a;">${fmtI(epfLumpsum)} <small>(Tax-free)</small></strong></div>
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;"><span>Monthly Pension</span><strong style="color:#6b7280;">EPS pension (variable)</strong></div>
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;"><span>Annual Tax Saved</span><strong style="color:#16a34a;">${fmtI(epfTaxSaved)}</strong></div>
          <div style="font-size:10px;color:#16a34a;margin-top:8px;padding:6px 8px;background:#dcfce7;border-radius:6px;">✅ 100% lump sum · EEE tax status · Fixed ${EPF_RATE}% guaranteed</div>
        </div>
      </div>

      <!-- Verdict -->
      <div style="padding:12px 16px;border-radius:10px;background:var(--bg-secondary);border-left:4px solid ${verdictColor};font-size:13px;">
        ${verdictText}<br>
        <span style="font-size:11px;color:var(--text-secondary);margin-top:4px;display:block;">
          💡 <strong>Best strategy:</strong> Maximize EPF (mandatory) + top up with NPS 80CCD(1B) ₹50,000 extra for maximum tax savings.
        </span>
      </div>

      <!-- Feature Comparison Table -->
      <div style="margin-top:16px;overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
          <thead>
            <tr style="background:var(--bg-secondary);">
              <th style="padding:8px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Feature</th>
              <th style="padding:8px 12px;text-align:center;font-weight:700;color:#1d4ed8;border-bottom:1px solid var(--border);">NPS</th>
              <th style="padding:8px 12px;text-align:center;font-weight:700;color:#15803d;border-bottom:1px solid var(--border);">EPF</th>
            </tr>
          </thead>
          <tbody>
            ${[
              ['Returns','Market-linked (8–14%)','Fixed 8.25% (Govt set)'],
              ['Tax on Investment','80C + 80CCD(1B) ₹2L total','80C up to ₹1.5L'],
              ['Tax on Corpus','60% tax-free, 40% taxable','100% tax-free (EEE)'],
              ['Withdrawal Flexibility','Low — locked till 60','Moderate — 5yr+ jobs'],
              ['Portability','High — any employer','Limited'],
              ['Partial Withdrawal','Yes (25%, 3 times, specific reasons)','Yes (after 5 yrs, up to 90%)'],
              ['Employer Contribution','Optional (10% of basic)','Mandatory (12% of basic)'],
              ['Risk','Moderate (market-linked)','None (guaranteed)'],
            ].map(([f,n,e])=>`<tr style="border-bottom:1px solid var(--border);">
              <td style="padding:8px 12px;color:var(--text-secondary);">${f}</td>
              <td style="padding:8px 12px;text-align:center;color:var(--text);">${n}</td>
              <td style="padding:8px 12px;text-align:center;color:var(--text);">${e}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>
    `;
  };

  document.addEventListener('DOMContentLoaded', function(){ t428Calc(); });
})();

// ═══════════════════════════════════════════════════════════════
// t429 — NPS Withdrawal Rules Tab Switcher
// ═══════════════════════════════════════════════════════════════
(function(){
  const TABS = ['maturity','premature','partial','death'];
  window.t429Tab = function(active){
    TABS.forEach(t=>{
      const panel = document.getElementById('t429Panel_'+t);
      const btn   = document.getElementById('t429Tab_'+t);
      if(!panel||!btn) return;
      const isActive = t===active;
      panel.style.display = isActive ? '' : 'none';
      btn.style.background = isActive ? 'var(--primary)' : 'var(--bg-secondary)';
      btn.style.color      = isActive ? '#fff' : 'var(--text)';
      btn.style.borderColor= isActive ? 'var(--primary)' : 'var(--border)';
    });
  };
})();

// ═══════════════════════════════════════════════════════════════
// t430 — NPS Contribution Reminder
// ═══════════════════════════════════════════════════════════════
(function(){
  const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

  function fmtI(n){ return '₹'+Math.round(n).toLocaleString('en-IN'); }

  window.t430Preview = function(){
    const target    = parseInt(document.getElementById('t430Target')?.value)||10000;
    const sipDay    = parseInt(document.getElementById('t430SipDay')?.value)||5;
    const daysBefore= parseInt(document.getElementById('t430DaysBefore')?.value)||1;

    const today = new Date();
    let nextDate = new Date(today.getFullYear(), today.getMonth(), sipDay - daysBefore);
    if(nextDate <= today) nextDate = new Date(today.getFullYear(), today.getMonth()+1, sipDay - daysBefore);

    const daysLeft = Math.ceil((nextDate - today) / 86400000);
    const opts = { weekday:'short', day:'numeric', month:'long', year:'numeric' };
    const dateStr = nextDate.toLocaleDateString('en-IN', opts);

    const prevText = document.getElementById('t430PreviewText');
    const prevSub  = document.getElementById('t430PreviewSub');
    if(prevText) prevText.textContent = daysLeft===0 ? '⚡ Reminder is TODAY!' : `📅 ${dateStr}`;
    if(prevSub)  prevSub.textContent  = daysLeft>0
      ? `${daysLeft} day${daysLeft>1?'s':''} until your ₹${target.toLocaleString('en-IN')} NPS contribution (SIP on ${sipDay}th)`
      : `Contribute ₹${target.toLocaleString('en-IN')} to NPS today!`;

    // Month grid
    const grid = document.getElementById('t430MonthGrid');
    if(grid){
      const cm = today.getMonth();
      const cy = today.getFullYear();
      grid.innerHTML = MONTHS.map((mn,i)=>{
        let bg,title;
        if(i < cm){ bg='#16a34a'; title=`${mn} — Assumed contributed`; }
        else if(i === cm){ bg='#d97706'; title=`${mn} — Current month (SIP on ${sipDay}th)`; }
        else{ bg='var(--border)'; title=`${mn} — Upcoming`; }
        return `<div title="${title}" style="aspect-ratio:1;background:${bg};border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:8px;font-weight:700;color:#fff;cursor:pointer;" title="${mn}">${mn.slice(0,1)}</div>`;
      }).join('');
    }

    // Tax bars
    const annualContrib = target * 12;
    const limit80CCD1  = 150000;
    const limit80CCD1B = 50000;
    const limit80CCD2  = 96000; // 10% of 8L salary (example)
    const taxBars = document.getElementById('t430TaxBars');
    if(taxBars){
      taxBars.innerHTML = [
        ['80CCD(1)', limit80CCD1, Math.min(annualContrib, limit80CCD1), '#3b82f6', 'Employee Contribution (within 80C ₹1.5L)'],
        ['80CCD(1B)', limit80CCD1B, Math.min(Math.max(annualContrib-limit80CCD1,0), limit80CCD1B), '#8b5cf6', 'Extra ₹50,000 deduction (over & above 80C)'],
        ['80CCD(2)', limit80CCD2, 0, '#10b981', "Employer's contribution (10% of Basic) — tax-free"],
      ].map(([label,limit,used,color,desc])=>`
        <div style="padding:10px;background:var(--bg-card);border-radius:8px;">
          <div style="font-size:11px;font-weight:800;color:${color};margin-bottom:6px;">${label}</div>
          <div style="background:var(--border);border-radius:4px;height:8px;margin-bottom:6px;overflow:hidden;">
            <div style="height:100%;width:${limit>0?Math.min(100,used/limit*100).toFixed(0):0}%;background:${color};border-radius:4px;transition:width .4s;"></div>
          </div>
          <div style="font-size:10px;color:var(--text-secondary);">${fmtI(used)} / ${fmtI(limit)}</div>
          <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">${desc}</div>
        </div>
      `).join('');
    }
  };

  window.t430Save = function(){
    const target    = document.getElementById('t430Target')?.value||10000;
    const sipDay    = document.getElementById('t430SipDay')?.value||5;
    const channel   = document.getElementById('t430Channel')?.value||'browser';
    const daysBefore= document.getElementById('t430DaysBefore')?.value||1;

    // Store preference
    try{
      localStorage.setItem('wd_nps_reminder', JSON.stringify({ target, sipDay, channel, daysBefore, savedAt: new Date().toISOString() }));
    }catch(e){}

    // Request browser notification permission
    if((channel==='browser'||channel==='both') && 'Notification' in window){
      Notification.requestPermission().then(perm=>{
        const msg = perm==='granted'
          ? `✅ Reminder saved! You'll be notified ${daysBefore} day(s) before the ${sipDay}th each month.`
          : `✅ Reminder saved! Enable browser notifications for alerts. (Check browser settings)`;
        showToast(msg, 'success');
      });
    } else {
      showToast('✅ NPS Contribution Reminder saved! (₹'+parseInt(target).toLocaleString('en-IN')+'/mo on '+sipDay+'th)', 'success');
    }
  };

  function showToast(msg, type){
    const t = document.createElement('div');
    t.style.cssText='position:fixed;bottom:24px;right:24px;background:'+(type==='success'?'#16a34a':'#ef4444')+';color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2);max-width:340px;';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(()=>t.remove(),4000);
  }

  // Load saved prefs
  document.addEventListener('DOMContentLoaded', function(){
    try{
      const saved = JSON.parse(localStorage.getItem('wd_nps_reminder')||'{}');
      if(saved.target) document.getElementById('t430Target').value = saved.target;
      if(saved.sipDay) document.getElementById('t430SipDay').value = saved.sipDay;
      if(saved.channel) document.getElementById('t430Channel').value = saved.channel;
      if(saved.daysBefore) document.getElementById('t430DaysBefore').value = saved.daysBefore;
    }catch(e){}
    t430Preview();
  });
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     t197 — NPS Contribution SIP Tracker
     ════════════════════════════════════════════════════════════════════════ -->
<style>
/* t197 */
.npssip-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:18px; }
.npssip-stat { background:var(--bg-secondary); border:1.5px solid var(--border); border-radius:10px; padding:12px 14px; text-align:center; }
.npssip-stat-label { font-size:10px; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:4px; letter-spacing:.4px; }
.npssip-stat-val   { font-size:18px; font-weight:800; color:var(--text-primary); }
.npssip-month-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:6px; margin-bottom:18px; }
.npssip-month-cell { border-radius:8px; padding:8px 6px; text-align:center; border:1.5px solid var(--border); font-size:11px; cursor:default; position:relative; }
.npssip-month-cell.contributed  { background:rgba(22,163,74,.10); border-color:#86efac; }
.npssip-month-cell.missed       { background:rgba(220,38,38,.06); border-color:#fca5a5; }
.npssip-month-cell.future       { background:var(--bg-secondary); opacity:.45; }
.npssip-month-cell .mc-name     { font-weight:700; color:var(--text-primary); margin-bottom:2px; }
.npssip-month-cell .mc-amt      { font-size:10px; color:var(--text-muted); }
.npssip-streak-badge { display:inline-flex; align-items:center; gap:5px; padding:5px 14px; border-radius:20px; background:rgba(234,179,8,.12); border:1.5px solid #fde047; color:#a16207; font-size:13px; font-weight:700; }
.npssip-tax-bar-wrap { margin-bottom:10px; }
.npssip-tax-bar-track { height:8px; background:var(--bg-tertiary,var(--bg-secondary)); border-radius:4px; overflow:hidden; margin:4px 0; }
.npssip-tax-bar-fill  { height:100%; border-radius:4px; transition:width .5s; }
.npssip-yoy-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:4px; }
.npssip-yoy-pill { flex:1; min-width:120px; background:var(--bg-secondary); border:1.5px solid var(--border); border-radius:10px; padding:10px 12px; }
@media(max-width:640px){ .npssip-grid{grid-template-columns:repeat(2,1fr);} .npssip-month-grid{grid-template-columns:repeat(3,1fr);} }
</style>

<div class="card mb-4" id="npsSipTrackerCard">
  <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
    <h3 class="card-title">📅 NPS Contribution SIP Tracker</h3>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <select id="npsSipTier" class="form-select" style="width:110px;font-size:13px;" onchange="NPSSipTracker.load()">
        <option value="">All Tiers</option>
        <option value="tier1">Tier I</option>
        <option value="tier2">Tier II</option>
      </select>
      <select id="npsSipFy" class="form-select" style="width:110px;font-size:13px;" onchange="NPSSipTracker.load()">
        <!-- FY options injected by JS -->
      </select>
    </div>
  </div>
  <div class="card-body">
    <div id="npsSipLoading" style="text-align:center;padding:36px;color:var(--text-muted);">Loading contribution data…</div>
    <div id="npsSipError"   style="display:none;color:#dc2626;padding:12px;background:rgba(220,38,38,.05);border-radius:8px;margin-bottom:12px;"></div>
    <div id="npsSipContent" style="display:none;">

      <!-- Streak + summary stats -->
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
        <div class="npssip-streak-badge" id="npsSipStreakBadge">🔥 0 Month Streak</div>
        <div class="npssip-streak-badge" style="background:rgba(99,102,241,.10);border-color:#a5b4fc;color:#4338ca;" id="npsSipLongestBadge">🏆 Longest: 0</div>
      </div>

      <div class="npssip-grid" id="npsSipStats"></div>

      <!-- Month heatmap -->
      <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;" id="npsSipFyLabel">FY</div>
      <div class="npssip-month-grid" id="npsSipMonthGrid"></div>

      <!-- Tax tracker -->
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px 16px;border:1.5px solid var(--border);margin-bottom:16px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:10px;">📋 80CCD Tax Tracker (Self Contributions)</div>
        <div class="npssip-tax-bar-wrap" id="npsSipTax80CCD1"></div>
        <div class="npssip-tax-bar-wrap" id="npsSipTax80CCD1B"></div>
        <div id="npsSipTax1BReminder" style="font-size:12px;color:var(--text-muted);margin-top:6px;"></div>
      </div>

      <!-- Year-on-Year -->
      <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;">📊 Year-on-Year Contribution</div>
      <div class="npssip-yoy-row" id="npsSipYoY"></div>
      <div style="margin-top:8px;height:160px;position:relative;"><canvas id="npsSipChart"></canvas></div>

    </div>
  </div>
</div>

<script>
const NPSSipTracker = (() => {
  let _chart = null;

  async function load() {
    document.getElementById('npsSipLoading').style.display = '';
    document.getElementById('npsSipContent').style.display = 'none';
    document.getElementById('npsSipError').style.display   = 'none';

    const fy   = document.getElementById('npsSipFy').value   || '';
    const tier = document.getElementById('npsSipTier').value || '';

    try {
      const params = new URLSearchParams({ action:'nps_sip_tracker' });
      if (fy)   params.append('year', fy);
      if (tier) params.append('tier', tier);
      const pid = document.querySelector('meta[name="portfolio-id"]')?.content || '';
      if (pid)  params.append('portfolio_id', pid);

      const res  = await fetch(`${window.APP_URL||''}/api/?${params}`);
      const json = await res.json();
      if (!json.success) throw new Error(json.message||'Failed');
      const d = json.data;

      // Populate FY selector on first load
      const fyEl = document.getElementById('npsSipFy');
      if (fyEl.options.length <= 1) {
        fyEl.innerHTML = '';
        (d.available_fys || []).reverse().forEach(f => {
          const opt = document.createElement('option');
          opt.value = f.year; opt.textContent = f.label;
          if (f.year === d.fy_year) opt.selected = true;
          fyEl.appendChild(opt);
        });
      }

      render(d);
    } catch(e) {
      document.getElementById('npsSipLoading').style.display = 'none';
      document.getElementById('npsSipError').style.display   = '';
      document.getElementById('npsSipError').textContent = '⚠️ ' + e.message;
    }
  }

  function render(d) {
    document.getElementById('npsSipLoading').style.display = 'none';
    document.getElementById('npsSipContent').style.display = '';
    document.getElementById('npsSipFyLabel').textContent   = d.fy + ' Contribution Calendar';

    const s = d.summary;
    // Streak badges
    document.getElementById('npsSipStreakBadge').textContent  = `🔥 ${s.current_streak} Month Streak`;
    document.getElementById('npsSipLongestBadge').textContent = `🏆 Longest: ${s.longest_streak} Months`;

    // Stats grid
    document.getElementById('npsSipStats').innerHTML = [
      { label:'FY Self',         val: INR(s.fy_self, true) },
      { label:'FY Employer',     val: INR(s.fy_employer, true) },
      { label:'FY Total',        val: INR(s.fy_total, true) },
      { label:'Monthly Avg',     val: INR(s.monthly_avg, true) },
      { label:'Months Contrib.', val: `${s.contributed_months} / ${s.months_elapsed}` },
      { label:'Months Remaining',val: s.months_remaining },
      { label:'Projected (FY)',   val: s.months_elapsed > 0 ? INR(Math.round(s.fy_total / s.months_elapsed * 12), true) : '—' },
      { label:'80CCD(1B) Room',  val: INR(d.tax_tracker.remaining_1b, true) },
    ].map(c => `<div class="npssip-stat"><div class="npssip-stat-label">${c.label}</div><div class="npssip-stat-val">${c.val}</div></div>`).join('');

    // Month heatmap
    const today = new Date(); const todayYm = today.toISOString().slice(0,7);
    document.getElementById('npsSipMonthGrid').innerHTML = (d.month_grid||[]).map(m => {
      const cls  = m.future ? 'future' : m.total > 0 ? 'contributed' : 'missed';
      const icon = m.future ? '' : m.total > 0 ? '✅' : '❌';
      return `<div class="npssip-month-cell ${cls}" title="${m.month}: ${INR(m.total,true)}">
        <div class="mc-name">${icon} ${m.month.split(' ')[0]}</div>
        <div class="mc-amt">${m.future ? '' : INR(m.total,true)}</div>
      </div>`;
    }).join('');

    // Tax bars
    const tx = d.tax_tracker;
    const pct1  = Math.min(100, tx.limit_80ccd1 > 0 ? (tx.used_80ccd1 / tx.limit_80ccd1 * 100) : 0).toFixed(1);
    const pct1b = Math.min(100, tx.limit_80ccd1b > 0 ? (tx.used_80ccd1b / tx.limit_80ccd1b * 100) : 0).toFixed(1);

    document.getElementById('npsSipTax80CCD1').innerHTML =
      `<div style="display:flex;justify-content:space-between;font-size:12px;"><span><strong>80CCD(1)</strong> — up to ₹1,50,000</span><span style="color:var(--text-muted);">${INR(tx.used_80ccd1,true)} / ${INR(tx.limit_80ccd1,true)} (${pct1}%)</span></div>
       <div class="npssip-tax-bar-track"><div class="npssip-tax-bar-fill" style="width:${pct1}%;background:#3b82f6;"></div></div>`;

    document.getElementById('npsSipTax80CCD1B').innerHTML =
      `<div style="display:flex;justify-content:space-between;font-size:12px;"><span><strong>80CCD(1B)</strong> — additional ₹50,000</span><span style="color:var(--text-muted);">${INR(tx.used_80ccd1b,true)} / ${INR(tx.limit_80ccd1b,true)} (${pct1b}%)</span></div>
       <div class="npssip-tax-bar-track"><div class="npssip-tax-bar-fill" style="width:${pct1b}%;background:#16a34a;"></div></div>`;

    document.getElementById('npsSipTax1BReminder').textContent =
      tx.remaining_1b > 0
        ? `💡 You can save ₹${INR(tx.remaining_1b)} more tax under 80CCD(1B) this FY.`
        : `✅ 80CCD(1B) limit fully utilised — maximum NPS tax benefit achieved!`;

    // YoY pills
    document.getElementById('npsSipYoY').innerHTML = (d.yoy||[]).map(y =>
      `<div class="npssip-yoy-pill">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">${y.fy}</div>
        <div style="font-size:17px;font-weight:800;color:var(--text-primary);">${INR(y.total,true)}</div>
        <div style="font-size:11px;color:var(--text-muted);">${y.months_contrib} months</div>
       </div>`
    ).join('');

    // YoY bar chart
    renderChart(d.yoy || []);
  }

  function renderChart(yoy) {
    const ctx = document.getElementById('npsSipChart').getContext('2d');
    if (_chart) _chart.destroy();
    _chart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: yoy.map(y => y.fy),
        datasets: [
          { label:'Self', data: yoy.map(y=>y.self), backgroundColor:'rgba(37,99,235,.75)', borderRadius:5 },
          { label:'Employer', data: yoy.map(y=>y.employer), backgroundColor:'rgba(22,163,74,.7)', borderRadius:5 },
        ],
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        plugins: { legend:{ position:'top', labels:{ font:{size:12} } },
                   tooltip:{ callbacks:{ label:c=>` ${c.dataset.label}: ${INR(c.raw,true)}` } } },
        scales: {
          x: { stacked:true, grid:{display:false}, ticks:{color:'var(--text-muted)',font:{size:11}} },
          y: { stacked:true, ticks:{ callback:v=>INR(v,true), color:'var(--text-muted)',font:{size:10} }, grid:{color:'rgba(128,128,128,.12)'} },
        },
      },
    });
  }

  document.addEventListener('DOMContentLoaded', () => load());
  return { load };
})();
</script>

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

<!-- ═══ NPS NAV History Chart Modal ═══ -->
<div class="modal-overlay" id="modalNpsChart" style="display:none;z-index:1100;">
  <div class="modal" style="max-width:860px;width:97%;max-height:92vh;display:flex;flex-direction:column;padding:0;overflow:hidden;">

    <!-- Header -->
    <div class="modal-header" style="flex-shrink:0;padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
      <div style="min-width:0;">
        <div id="ncFundName" style="font-size:15px;font-weight:700;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:680px;">—</div>
        <div id="ncFundMeta" style="font-size:11px;color:var(--text-muted);margin-top:4px;display:flex;gap:6px;flex-wrap:wrap;"></div>
      </div>
      <button class="btn btn-ghost btn-sm" onclick="NPS.closeChartModal()" style="flex-shrink:0;font-size:16px;line-height:1;padding:6px 10px;">✕</button>
    </div>

    <!-- Stats strip -->
    <div id="ncStats" style="flex-shrink:0;display:grid;grid-template-columns:repeat(5,1fr);gap:0;border-bottom:1px solid var(--border);background:var(--bg-secondary);"></div>

    <!-- Chart area -->
    <div style="flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden;">

      <!-- Range controls -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px 0;flex-shrink:0;flex-wrap:wrap;gap:8px;">
        <div style="display:flex;gap:4px;" id="ncRangeBtns">
          <button class="nc-range-btn" data-range="3M"  onclick="NPS.setChartRange('3M',this)">3M</button>
          <button class="nc-range-btn" data-range="6M"  onclick="NPS.setChartRange('6M',this)">6M</button>
          <button class="nc-range-btn nc-range-btn-active" data-range="1Y"  onclick="NPS.setChartRange('1Y',this)">1Y</button>
          <button class="nc-range-btn" data-range="3Y"  onclick="NPS.setChartRange('3Y',this)">3Y</button>
          <button class="nc-range-btn" data-range="ALL" onclick="NPS.setChartRange('ALL',this)">All</button>
        </div>
        <div style="display:flex;align-items:center;gap:12px;font-size:11px;color:var(--text-muted);">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;">
            <input type="checkbox" id="ncShowTxns" checked onchange="NPS.toggleChartTxns()" style="accent-color:var(--accent);"> Show contributions
          </label>
          <span id="ncDataStatus" style="color:var(--text-muted);"></span>
        </div>
      </div>

      <!-- Canvas -->
      <div style="flex:1;min-height:280px;padding:8px 16px 0;position:relative;" id="ncCanvasWrap">
        <div id="ncChartSpinner" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:var(--bg-card);z-index:5;">
          <div class="spinner"></div>
        </div>
        <div id="ncNoData" style="display:none;position:absolute;inset:0;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:var(--text-muted);">
          <div style="font-size:28px;">📉</div>
          <div style="font-size:13px;font-weight:600;">NAV history not available</div>
          <div style="font-size:12px;">Admin → NAV &amp; Data se NPS history download karo</div>
        </div>
        <canvas id="ncChartCanvas" style="width:100%!important;height:100%!important;display:none;"></canvas>
      </div>

      <!-- Contribution pills -->
      <div style="flex-shrink:0;padding:10px 16px 14px;border-top:1px solid var(--border);margin-top:8px;max-height:130px;overflow-y:auto;" id="ncTxnSection">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Contributions</div>
        <div id="ncTxnList" style="display:flex;flex-wrap:wrap;gap:5px;"></div>
      </div>

    </div>
  </div>
</div>

<style>
.nc-range-btn{padding:4px 12px;border-radius:99px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-muted);font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;}
.nc-range-btn:hover,.nc-range-btn-hover{border-color:var(--accent);color:var(--accent);}
.nc-range-btn-active,.nc-range-btn.active{background:var(--accent);color:#fff;border-color:var(--accent);}
.nc-stat-cell{padding:10px 8px;border-right:1px solid var(--border-color);text-align:center;}
.nc-stat-cell:last-child{border-right:none;}
.nc-stat-label{font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;}
.nc-stat-val{font-size:13px;font-weight:800;color:var(--text-primary);}
.nc-stat-sub{font-size:10px;color:var(--text-muted);margin-top:2px;}
.nps-scheme-clickable{cursor:pointer;transition:color .15s;}
.nps-scheme-clickable:hover{color:var(--accent);text-decoration:underline;}
</style>


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
  loadNpsAllocAnalyzer(); // t273
});

/* ── t273: NPS Auto-Allocation Analyzer ──────────────────────── */
async function loadNpsAllocAnalyzer() {
  const wrap = document.getElementById('npsAllocAnalyzerWrap');
  if (!wrap) return;
  const BASE = window.APP_URL || window.WD?.appUrl || '';
  const CSRF = window.WD?.csrf || window.CSRF_TOKEN || '';
  const age  = parseInt(document.getElementById('npsAllocAge')?.value || 0) || 35;

  wrap.innerHTML = '<div style="text-align:center;padding:20px;"><span class="spinner"></span></div>';
  try {
    const fd = new FormData();
    fd.append('action', 'nps_allocation_analyzer');
    fd.append('current_age', age);
    if (CSRF) fd.append('_csrf_token', CSRF);
    const res  = await fetch(`${BASE}/api/?action=nps_allocation_analyzer`, {method:'POST',body:fd});
    const data = await res.json();
    if (!data.success) throw new Error(data.message || 'API error');
    const d = data.allocation_analyzer;
    renderNpsAllocAnalyzer(d);
  } catch(e) {
    wrap.innerHTML = `<p style="color:var(--text-secondary);text-align:center;">Could not load analyzer: ${e.message}</p>`;
  }
}

function renderNpsAllocAnalyzer(d) {
  const wrap = document.getElementById('npsAllocAnalyzerWrap');
  if (!wrap) return;
  const inr = v => '₹' + Number(v||0).toLocaleString('en-IN',{maximumFractionDigits:0});
  const pct = (v, cls='') => `<strong style="color:${cls||'var(--text)'};">${Number(v||0).toFixed(1)}%</strong>`;

  const alertsHtml = (d.alerts||[]).map(a => `
    <div style="padding:10px 14px;border-left:3px solid ${a.level==='error'?'#ef4444':'#f59e0b'};background:var(--bg-secondary);border-radius:0 6px 6px 0;font-size:13px;margin-bottom:8px;">
      ${a.level==='error'?'🔴':'⚠️'} ${a.message}
    </div>`).join('');

  const rebalHtml = (d.rebalance||[]).map(r => `
    <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--bg-secondary);border-radius:8px;font-size:13px;margin-bottom:6px;">
      <span style="font-size:16px;">${r.action==='Increase'?'📈':'📉'}</span>
      <div style="flex:1;">
        <strong>${r.asset}</strong>: ${r.action} from ${r.from_pct}% → ${r.to_pct}%
        <div style="font-size:12px;color:var(--text-secondary);">Amount: ~${inr(r.by_amount)}</div>
      </div>
    </div>`).join('');

  // LC comparison table
  const lcHtml = Object.entries(d.recommended||{}).map(([key, lc]) => `
    <tr style="${key===d.default_choice?'background:rgba(99,102,241,.08);font-weight:600;':''}">
      <td>${lc.name} ${key===d.default_choice?'<span style="font-size:10px;background:var(--accent);color:#fff;padding:1px 6px;border-radius:10px;margin-left:4px;">Recommended</span>':''}</td>
      <td class="text-right">${lc.equity}%</td>
      <td class="text-right">${lc.corporate}%</td>
      <td class="text-right">${lc.govt}%</td>
    </tr>`).join('');

  // Actual vs target bars
  const barRow = (label, actual, target, color) => {
    const maxW = 200;
    const aW = Math.round(Math.min(actual/100,1)*maxW);
    const tW = Math.round(Math.min(target/100,1)*maxW);
    return `<div style="margin-bottom:10px;">
      <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;">
        <span>${label}</span>
        <span>Actual ${actual}% · Target ${target}%</span>
      </div>
      <div style="background:var(--border);border-radius:4px;height:8px;width:${maxW}px;position:relative;">
        <div style="background:${color};width:${aW}px;height:8px;border-radius:4px;"></div>
        <div style="position:absolute;top:-2px;left:${tW}px;width:2px;height:12px;background:#374151;border-radius:1px;" title="Target ${target}%"></div>
      </div>
    </div>`;
  };

  const actual = d.actual_pct || {};
  const target = d.target || {};

  wrap.innerHTML = d.has_holdings ? `
    ${alertsHtml ? `<div style="margin-bottom:16px;">${alertsHtml}</div>` : ''}

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
      <!-- Actual vs Target bars -->
      <div class="card" style="padding:16px;">
        <div style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px;">Actual vs Recommended (${d.default_choice?.toUpperCase()})</div>
        ${barRow('Equity (E)', actual.equity||0, target.equity||0, '#3b82f6')}
        ${barRow('Corporate Bonds (C)', actual.corporate||0, target.corporate||0, '#10b981')}
        ${barRow('Govt Bonds (G)', actual.govt||0, target.govt||0, '#8b5cf6')}
      </div>
      <!-- LC Fund comparison -->
      <div class="card" style="padding:16px;">
        <div style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px;">PFRDA Lifecycle Funds (Age ${d.current_age})</div>
        <table class="table" style="font-size:12px;"><thead><tr><th>Fund</th><th class="text-right">Equity</th><th class="text-right">Corp.</th><th class="text-right">Govt</th></tr></thead>
        <tbody>${lcHtml}</tbody></table>
      </div>
    </div>

    ${rebalHtml ? `<div style="margin-bottom:16px;"><div style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">🔄 Rebalancing Suggestions</div>${rebalHtml}</div>` : '<div style="padding:10px 14px;background:var(--bg-secondary);border-radius:8px;font-size:13px;color:#16a34a;margin-bottom:16px;">✅ Your allocation is well-aligned with recommendations.</div>'}

    <div style="font-size:12px;color:var(--text-secondary);padding:10px 14px;background:var(--bg-secondary);border-radius:8px;">
      💡 <strong>Tip:</strong> Under Active Choice, you can set up to 75% equity. Under Auto Choice (Lifecycle), PFRDA automatically reduces equity as you age. Switch in your NPS account portal or via employer.
    </div>` :
    `<div style="text-align:center;padding:24px;color:var(--text-secondary);">
      <div style="font-size:40px;margin-bottom:8px;">📊</div>
      <div>No NPS Tier I holdings found. Add your NPS contributions to see allocation analysis.</div>
    </div>`;
}
</script>
<?php
$pageContent = ob_get_clean();
$extraScripts = '<script src="' . APP_URL . '/public/js/charts.js?v=' . ASSET_VERSION . '"></script>'
             . '<script src="' . APP_URL . '/public/js/nps.js?v=' . ASSET_VERSION . '"></script>';
include APP_ROOT . '/templates/layout.php';