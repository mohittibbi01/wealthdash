<?php
/**
 * WealthDash — Fixed Deposits Page
 * Phase 4 — Complete: FD list, add, maturity tracker, TDS calc, annual accrual
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Fixed Deposits';
$activePage  = 'fd';

$db = DB::conn();
$today = date('Y-m-d');

$summaryStmt = $db->prepare("
    SELECT COUNT(*) AS fd_count,
           SUM(principal) AS total_principal,
           SUM(maturity_amount) AS total_maturity,
           SUM(maturity_amount - principal) AS total_interest
    FROM fd_accounts fa
    JOIN portfolios p ON p.id = fa.portfolio_id
    WHERE p.user_id = ? AND fa.status = 'active'
");
$summaryStmt->execute([$currentUser['id']]);
$summary         = $summaryStmt->fetch();
$totalPrincipal  = (float)($summary['total_principal'] ?? 0);
$totalMaturity   = (float)($summary['total_maturity'] ?? 0);
$totalInterest   = (float)($summary['total_interest'] ?? 0);
$fdCount         = (int)($summary['fd_count'] ?? 0);

// FDs maturing in next 90 days
$alertStmt = $db->prepare("
    SELECT fa.*, p.name AS portfolio_name, DATEDIFF(fa.maturity_date, CURDATE()) AS days_left
    FROM fd_accounts fa
    JOIN portfolios p ON p.id = fa.portfolio_id
    WHERE p.user_id=? AND fa.status='active' AND fa.maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER BY fa.maturity_date ASC
");
$alertStmt->execute([$currentUser['id']]);
$upcomingFds = $alertStmt->fetchAll();

$pStmt = $db->prepare("SELECT id, name, color FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Fixed Deposits</h1>
    <p class="page-subtitle">Cumulative FDs — with TDS &amp; annual accrual tracking</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary" id="btnAddFd">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add FD
    </button>
  </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label">Active FDs</div>
    <div class="stat-value"><?= $fdCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Principal Invested</div>
    <div class="stat-value"><?= inr($totalPrincipal) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Maturity Value</div>
    <div class="stat-value text-success"><?= inr($totalMaturity) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Interest</div>
    <div class="stat-value text-success"><?= inr($totalInterest) ?></div>
  </div>
</div>

<?php if ($upcomingFds): ?>
<!-- Maturity Alerts -->
<div class="card" style="margin-bottom:24px;border-left:4px solid var(--warning)">
  <div class="card-header"><h3 class="card-title">⚠️ Maturing in Next 90 Days</h3></div>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Bank</th><th>Account No.</th><th>Principal</th><th>Maturity Amount</th><th>Maturity Date</th><th>Days Left</th></tr></thead>
      <tbody>
        <?php foreach ($upcomingFds as $f): ?>
        <tr>
          <td><?= e($f['bank_name']) ?></td>
          <td><?= $f['account_number'] ? '****' . substr($f['account_number'], -4) : '—' ?></td>
          <td class="text-right"><?= inr($f['principal']) ?></td>
          <td class="text-right text-success"><?= inr($f['maturity_amount']) ?></td>
          <td><?= fmt_date($f['maturity_date']) ?></td>
          <td><span class="badge <?= $f['days_left'] <= 30 ? 'badge-danger' : 'badge-warning' ?>"><?= $f['days_left'] ?> days</span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- FD Table -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div class="view-toggle">
      <button class="view-btn active" data-status="active">Active</button>
      <button class="view-btn" data-status="matured">Matured</button>
      <button class="view-btn" data-status="">All</button>
    </div>
    <div style="display:flex;gap:8px">
      <input type="text" class="form-input" id="searchBank" placeholder="Search bank..." style="width:160px" oninput="FD.filterTable()">
      <select class="form-select" id="filterPortfolio" style="width:160px" onchange="FD.loadFds()">
        <option value="">All Portfolios</option>
        <?php foreach ($portfolios as $p): ?>
        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table" id="fdTable">
      <thead>
        <tr>
          <th>Bank</th>
          <th>Account No.</th>
          <th>Portfolio</th>
          <th class="text-right">Principal</th>
          <th class="text-right">Rate %</th>
          <th>Start Date</th>
          <th>Maturity Date</th>
          <th class="text-right">Maturity Amount</th>
          <th class="text-right">Interest Earned</th>
          <th>Status</th>
          <th class="text-right">Accrued (This FY)</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="fdBody">
        <tr><td colspan="12" class="text-center" style="padding:40px;color:var(--text-muted)"><span class="spinner"></span> Loading...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Add FD Modal -->
<div class="modal-overlay" id="modalAddFd" style="display:none">
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <h3 class="modal-title" id="fdModalTitle">Add Fixed Deposit</h3>
      <button class="modal-close" id="closeFdModal">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Portfolio *</label>
          <select class="form-select" id="fdPortfolio">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Bank Name *</label>
          <input type="text" class="form-input" id="fdBankName" placeholder="SBI, HDFC, ICICI..." list="bankList">
          <datalist id="bankList">
            <option>SBI</option><option>HDFC Bank</option><option>ICICI Bank</option>
            <option>Axis Bank</option><option>Kotak Bank</option><option>PNB</option>
            <option>Bank of Baroda</option><option>Canara Bank</option>
            <option>Yes Bank</option><option>IndusInd Bank</option>
          </datalist>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Account / FD Number</label>
          <input type="text" class="form-input" id="fdAccountNumber" placeholder="Optional">
        </div>
        <div class="form-group">
          <label class="form-label">Principal Amount (₹) *</label>
          <input type="number" class="form-input" id="fdPrincipal" step="1" min="1" placeholder="100000" oninput="FD.calcMaturity()">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Interest Rate (% p.a.) *</label>
          <input type="number" class="form-input" id="fdRate" step="0.01" min="0.01" max="25" placeholder="7.50" oninput="FD.calcMaturity()">
        </div>
        <div class="form-group">
          <label class="form-label">Compounding</label>
          <select class="form-select" id="fdCompounding" onchange="FD.calcMaturity()">
            <option value="1">Annual</option>
            <option value="4" selected>Quarterly</option>
            <option value="12">Monthly</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Start Date *</label>
          <input type="date" class="form-input" id="fdStartDate" onchange="FD.calcMaturity()">
        </div>
        <div class="form-group">
          <label class="form-label">Maturity Date *</label>
          <input type="date" class="form-input" id="fdMaturityDate" onchange="FD.calcMaturity()">
        </div>
      </div>
      <!-- Preview -->
      <div id="fdPreview" style="display:none;background:var(--surface-hover);border-radius:8px;padding:12px;margin:8px 0">
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span>Tenure:</span><strong id="prevTenure"></strong>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-top:4px">
          <span>Maturity Amount:</span><strong id="prevMaturity" class="text-success"></strong>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-top:4px">
          <span>Total Interest:</span><strong id="prevInterest" class="text-success"></strong>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-top:4px">
          <span>Effective Yield:</span><strong id="prevYield"></strong>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Senior Citizen Rate?</label>
          <select class="form-select" id="fdSenior">
            <option value="0">No (Regular rate)</option>
            <option value="1">Yes (+0.25–0.50% extra)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">TDS Applicable?</label>
          <select class="form-select" id="fdTds">
            <option value="1">Yes (10% if >₹40,000/yr)</option>
            <option value="0">No (Form 15G/H submitted)</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" class="form-input" id="fdNotes" placeholder="Renewal, purpose, etc.">
      </div>
      <div id="fdError" class="form-error" style="display:none"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelFd">Cancel</button>
      <button class="btn btn-primary" id="saveFd">Save FD</button>
    </div>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="modalDelFd" style="display:none">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3 class="modal-title">Delete FD?</h3><button class="modal-close" id="closeDelFd">✕</button></div>
    <div class="modal-body"><p>Are you sure you want to delete this Fixed Deposit? This cannot be undone.</p></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelDelFd">Cancel</button>
      <button class="btn btn-danger" id="confirmDelFd">Delete</button>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/public/js/fd.js?v=<?= ASSET_VERSION ?>"></script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';

