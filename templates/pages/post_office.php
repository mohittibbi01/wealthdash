<?php
/**
 * WealthDash — Post Office Schemes Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Post Office Schemes';
$activePage  = 'post_office';

$db = DB::conn();

// Portfolios for filter/add
$portfolios = DB::fetchAll(
    "SELECT id, name, color FROM portfolios WHERE user_id=? ORDER BY name ASC",
    [$currentUser['id']]
);

// Summary across all active PO schemes
$summary = DB::fetchOne(
    "SELECT
        COUNT(*)                          AS total_count,
        SUM(po.principal)                 AS total_invested,
        SUM(po.maturity_amount)           AS total_maturity,
        SUM(po.maturity_amount - po.principal) AS total_interest
     FROM po_schemes po
     JOIN portfolios p ON p.id = po.portfolio_id
     WHERE p.user_id = ? AND po.status = 'active'",
    [$currentUser['id']]
) ?: [];

$totalCount    = (int)($summary['total_count']    ?? 0);
$totalInvested = (float)($summary['total_invested'] ?? 0);
$totalMaturity = (float)($summary['total_maturity'] ?? 0);
$totalInterest = (float)($summary['total_interest'] ?? 0);

// Maturing within 90 days
$maturing = DB::fetchAll(
    "SELECT po.*, p.name AS portfolio_name,
            DATEDIFF(po.maturity_date, CURDATE()) AS days_left
     FROM po_schemes po
     JOIN portfolios p ON p.id = po.portfolio_id
     WHERE p.user_id=? AND po.status='active'
       AND po.maturity_date IS NOT NULL
       AND po.maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
     ORDER BY po.maturity_date ASC",
    [$currentUser['id']]
);

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">📮 Post Office Schemes</h1>
    <p class="page-subtitle">Government-backed savings — PO Savings, RD, TD, MIS, SCSS, PPF, SSY, NSC, KVP</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" id="btnAddPo">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      Add Scheme
    </button>
  </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label">Active Schemes</div>
    <div class="stat-value" id="poTotalCount"><?= $totalCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Invested</div>
    <div class="stat-value" id="poTotalInvested"><?= inr($totalInvested) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Maturity Value</div>
    <div class="stat-value text-success" id="poTotalMaturity"><?= inr($totalMaturity) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Interest</div>
    <div class="stat-value text-success" id="poTotalInterest"><?= inr($totalInterest) ?></div>
  </div>
</div>

<?php if ($maturing): ?>
<!-- Maturity Alert -->
<div class="card" style="margin-bottom:24px;border-left:4px solid var(--warning,#f59e0b)">
  <div class="card-header">
    <h3 class="card-title">⚠️ Maturing in Next 90 Days</h3>
  </div>
  <div class="table-wrapper">
    <table class="table">
      <thead>
        <tr>
          <th>Scheme</th><th>Holder</th><th>Account No.</th>
          <th class="text-right">Principal</th>
          <th class="text-right">Maturity Amt</th>
          <th>Maturity Date</th><th>Days Left</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($maturing as $m): ?>
        <tr>
          <td><strong><?= e($m['scheme_type']) ?></strong></td>
          <td><?= e($m['holder_name']) ?></td>
          <td style="font-family:monospace;font-size:12px;color:var(--text-muted)">
            <?= $m['account_number'] ? '••••'.substr($m['account_number'],-4) : '—' ?>
          </td>
          <td class="text-right"><?= inr($m['principal']) ?></td>
          <td class="text-right text-success"><?= inr($m['maturity_amount']) ?></td>
          <td><?= fmt_date($m['maturity_date']) ?></td>
          <td>
            <span class="badge <?= (int)$m['days_left'] <= 30 ? 'badge-danger' : 'badge-warning' ?>">
              <?= $m['days_left'] ?> days
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Scheme Type Quick Filter chips -->
<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;" id="schemeChips">
  <button class="po-chip po-chip-active" data-type="">All</button>
  <button class="po-chip" data-type="savings_account">🏦 PO Savings</button>
  <button class="po-chip" data-type="rd">🔄 RD</button>
  <button class="po-chip" data-type="td">📅 TD</button>
  <button class="po-chip" data-type="mis">💰 MIS</button>
  <button class="po-chip" data-type="scss">👴 SCSS</button>
  <button class="po-chip" data-type="ppf">🛡️ PPF</button>
  <button class="po-chip" data-type="ssy">👧 SSY</button>
  <button class="po-chip" data-type="nsc">📜 NSC</button>
  <button class="po-chip" data-type="kvp">🌾 KVP</button>
</div>

<!-- Main Table -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div class="mf-view-toggle">
      <button class="view-btn active" data-status="active">Active</button>
      <button class="view-btn" data-status="matured">Matured</button>
      <button class="view-btn" data-status="">All</button>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <input type="text" class="form-input" id="poSearch" placeholder="Search holder / account..."
             style="width:200px" oninput="PO.filterTable()">
      <select class="form-select" id="poFilterPortfolio" style="width:160px" onchange="PO.load()">
        <option value="">All Portfolios</option>
        <?php foreach ($portfolios as $p): ?>
        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="table-wrapper">
    <table class="table" id="poTable">
      <thead>
        <tr>
          <th>Scheme</th>
          <th>Holder</th>
          <th>Account No.</th>
          <th>Portfolio</th>
          <th class="text-right">Principal</th>
          <th class="text-right">Rate %</th>
          <th>Open Date</th>
          <th>Maturity Date</th>
          <th class="text-right">Maturity Amt</th>
          <th class="text-right">Interest</th>
          <th class="text-right">Days Left</th>
          <th>Status</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="poBody">
        <tr>
          <td colspan="13" class="text-center" style="padding:40px;color:var(--text-muted)">
            <span class="spinner"></span> Loading...
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ ADD / EDIT MODAL ═══ -->
<div class="modal-overlay" id="modalAddPo" style="display:none">
  <div class="modal" style="max-width:620px;width:95%">
    <div class="modal-header">
      <h3 class="modal-title" id="poModalTitle">Add Post Office Scheme</h3>
      <button class="modal-close" id="closePo">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="poEditId">

      <!-- Scheme Type Selector -->
      <div class="form-group" id="schemeTypeGroup">
        <label class="form-label">Scheme Type *</label>
        <div id="poSchemeGrid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:4px">
          <!-- Populated by JS -->
        </div>
      </div>

      <div id="poFormFields" style="display:none">
        <!-- Selected scheme info banner -->
        <div id="poSchemeBanner" style="border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:13px"></div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Portfolio *</label>
            <select class="form-select" id="poPortfolio">
              <?php foreach ($portfolios as $p): ?>
              <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Holder Name *</label>
            <input type="text" class="form-input" id="poHolder" placeholder="Account holder name">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Account / Certificate No.</label>
            <input type="text" class="form-input" id="poAccountNum" placeholder="Optional">
          </div>
          <div class="form-group">
            <label class="form-label">Post Office Branch</label>
            <input type="text" class="form-input" id="poPostOffice" placeholder="Branch name">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group" id="poRdGroup" style="display:none">
            <label class="form-label">Monthly Deposit (₹) *</label>
            <input type="number" class="form-input" id="poDeposit" step="1" min="100"
                   placeholder="Monthly instalment" oninput="PO.calcPreview()">
          </div>
          <div class="form-group" id="poPrincipalGroup">
            <label class="form-label" id="poPrincipalLabel">Principal Amount (₹) *</label>
            <input type="number" class="form-input" id="poPrincipal" step="1" min="1"
                   placeholder="Deposit amount" oninput="PO.calcPreview()">
          </div>
          <div class="form-group">
            <label class="form-label">Interest Rate (% p.a.) *</label>
            <input type="number" class="form-input" id="poRate" step="0.01" min="0.01" max="15"
                   placeholder="7.10" oninput="PO.calcPreview()">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Open Date *</label>
            <input type="date" class="form-input" id="poOpenDate" onchange="PO.calcPreview()">
          </div>
          <div class="form-group">
            <label class="form-label">Maturity Date</label>
            <input type="date" class="form-input" id="poMaturityDate" onchange="PO.calcPreview()">
            <small style="color:var(--text-muted);font-size:11px">Auto-filled based on scheme tenure</small>
          </div>
        </div>

        <!-- Preview Box -->
        <div id="poPreview" style="display:none;background:var(--bg-secondary,#f8fafc);border-radius:8px;padding:12px;margin:8px 0 16px">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;font-size:13px">
            <div><span style="color:var(--text-muted)">Tenure</span><br><strong id="prevTenure">—</strong></div>
            <div><span style="color:var(--text-muted)">Maturity Amount</span><br>
              <strong id="prevMat" style="color:#16a34a">—</strong></div>
            <div><span style="color:var(--text-muted)">Total Interest</span><br>
              <strong id="prevInt" style="color:#16a34a">—</strong></div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Nominee</label>
            <input type="text" class="form-input" id="poNominee" placeholder="Nominee name">
          </div>
          <div class="form-group">
            <label class="form-label">Joint Account?</label>
            <select class="form-select" id="poJoint">
              <option value="0">No — Single</option>
              <option value="1">Yes — Joint</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Notes</label>
          <input type="text" class="form-input" id="poNotes" placeholder="Renewal, purpose...">
        </div>

        <div id="poError" class="form-error" style="display:none"></div>
      </div><!-- /poFormFields -->
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelPo">Cancel</button>
      <button class="btn btn-primary" id="savePo" style="display:none">Save Scheme</button>
    </div>
  </div>
</div>

<!-- Delete Confirm -->
<div class="modal-overlay" id="modalDelPo" style="display:none">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <h3 class="modal-title">Delete Scheme?</h3>
      <button class="modal-close" id="closeDelPo">✕</button>
    </div>
    <div class="modal-body">
      <p>Are you sure you want to delete this scheme? This cannot be undone.</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelDelPo">Cancel</button>
      <button class="btn btn-danger" id="confirmDelPo">Delete</button>
    </div>
  </div>
</div>

<style>
.po-chip {
  padding: 5px 14px;
  border-radius: 99px;
  border: 1.5px solid var(--border);
  background: transparent;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  color: var(--text-secondary);
  transition: all .15s;
}
.po-chip:hover { border-color: var(--accent); color: var(--accent); }
.po-chip-active { background: var(--accent); color: #fff !important; border-color: var(--accent); }

.po-scheme-card {
  padding: 10px 12px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  cursor: pointer;
  transition: all .15s;
  font-size: 12px;
  text-align: left;
}
.po-scheme-card:hover { border-color: var(--accent); background: var(--bg-surface-2); }
.po-scheme-card.selected { border-color: var(--accent); background: rgba(37,99,235,.07); }
.po-scheme-card .sc-icon { font-size: 20px; margin-bottom: 4px; }
.po-scheme-card .sc-label { font-weight: 700; font-size: 11px; color: var(--text-primary); }
.po-scheme-card .sc-rate { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
</style>

<script src="<?= APP_URL ?>/public/js/post_office.js?v=<?= ASSET_VERSION ?>"></script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
