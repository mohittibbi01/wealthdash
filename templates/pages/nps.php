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

$pStmt = $db->prepare("SELECT id, name, color FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();

$schemesAll = $db->query("SELECT id, pfm_name, scheme_name, tier, latest_nav, latest_nav_date FROM nps_schemes ORDER BY pfm_name, tier, scheme_name")->fetchAll();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">NPS — National Pension System</h1>
    <p class="page-subtitle">Tier I &amp; Tier II holdings with PFRDA NAV</p>
  </div>
  <div class="page-header-actions">
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

<!-- Holdings Table -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div class="view-toggle">
      <button class="view-btn active" data-tier="">All</button>
      <button class="view-btn" data-tier="tier1">Tier I</button>
      <button class="view-btn" data-tier="tier2">Tier II</button>
    </div>
    <select class="form-select" id="filterPortfolio" style="width:160px">
      <option value="">All Portfolios</option>
      <?php foreach ($portfolios as $p): ?>
      <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Scheme / PFM</th>
          <th>Tier</th>
          <th class="text-right">Units</th>
          <th class="text-right">NAV (₹)</th>
          <th class="text-right">Invested</th>
          <th class="text-right">Current Value</th>
          <th class="text-right">Gain / Loss</th>
          <th class="text-right">CAGR</th>
          <th class="text-right">Since</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="npsHoldingsBody">
        <tr><td colspan="10" class="text-center" style="padding:40px;color:var(--text-muted)">
          <span class="spinner"></span> Loading holdings...
        </td></tr>
      </tbody>
    </table>
  </div>
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

<!-- Add Contribution Modal -->
<div class="modal-overlay" id="modalAddNps" style="display:none">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3 class="modal-title">Add NPS Contribution</h3>
      <button class="modal-close" id="closeModalNps">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Portfolio *</label>
        <select class="form-select" id="npsPortfolio">
          <?php foreach ($portfolios as $p): ?>
          <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
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

<script>window.NPS_SCHEMES_DATA = <?= json_encode($schemesAll, JSON_HEX_TAG) ?>;</script>
<script src="<?= APP_URL ?>/public/js/nps.js?v=<?= ASSET_VERSION ?>"></script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';

