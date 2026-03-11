<?php
/**
 * WealthDash — Savings Accounts Page
 * Phase 4 — Complete: Multiple banks, balance tracking, monthly interest, 80TTA
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Savings Accounts';
$activePage  = 'savings';

$db = DB::conn();

$summaryStmt = $db->prepare("
    SELECT COUNT(*) AS account_count,
           SUM(current_balance) AS total_balance,
           SUM(annual_interest_earned) AS total_interest
    FROM savings_accounts sa
    JOIN portfolios p ON p.id = sa.portfolio_id
    WHERE p.user_id = ? AND sa.is_active = 1
");
$summaryStmt->execute([$currentUser['id']]);
$summary       = $summaryStmt->fetch();
$totalBalance  = (float)($summary['total_balance']  ?? 0);
$totalInterest = (float)($summary['total_interest'] ?? 0);
$acctCount     = (int)($summary['account_count']    ?? 0);

// 80TTA limit: ₹10,000 for regular, ₹50,000 for senior
$ttaLimit     = $currentUser['is_senior_citizen'] ? 50000 : 10000;
$ttaUsed      = min($totalInterest, $ttaLimit);
$ttaRemaining = max(0, $ttaLimit - $totalInterest);

$pStmt = $db->prepare("SELECT id, name, color FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Savings Accounts</h1>
    <p class="page-subtitle">Bank savings — balance, interest &amp; Section 80TTA/80TTB tracker</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost" id="btnAddInterestEntry">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Record Interest
    </button>
    <button class="btn btn-primary" id="btnAddAccount">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Account
    </button>
  </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label">Bank Accounts</div>
    <div class="stat-value"><?= $acctCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Balance</div>
    <div class="stat-value"><?= inr($totalBalance) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Annual Interest (This FY)</div>
    <div class="stat-value text-success"><?= inr($totalInterest) ?></div>
  </div>
  <div class="stat-card" title="Section 80TTA/80TTB deduction">
    <div class="stat-label">80TTA<?= $currentUser['is_senior_citizen'] ? 'B' : '' ?> Used / Limit</div>
    <div class="stat-value <?= $totalInterest >= $ttaLimit ? 'text-danger' : 'text-success' ?>">
      <?= inr($ttaUsed) ?> / <?= inr($ttaLimit) ?>
      <span class="stat-sub"><?= $ttaRemaining > 0 ? inr($ttaRemaining) . ' remaining' : '⚠️ Limit exceeded' ?></span>
    </div>
  </div>
</div>

<!-- 80TTA Info Box -->
<?php if ($totalInterest > $ttaLimit): ?>
<div class="card" style="margin-bottom:20px;border-left:4px solid var(--danger)">
  <div class="card-body" style="padding:12px 16px">
    <strong>⚠️ Taxable Savings Interest:</strong> Your savings interest of <?= inr($totalInterest) ?> exceeds the ₹<?= number_format($ttaLimit) ?> 80TT<?= $currentUser['is_senior_citizen'] ? 'B' : 'A' ?> limit.
    <strong><?= inr($totalInterest - $ttaLimit) ?></strong> is taxable as per your income slab.
  </div>
</div>
<?php endif; ?>

<!-- Accounts List -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
    <h3 class="card-title">Bank Accounts</h3>
    <select class="form-select" id="filterPortfolio" style="width:160px" onchange="SAV.loadAccounts()">
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
          <th>Bank</th>
          <th>Account No.</th>
          <th>Account Type</th>
          <th>Portfolio</th>
          <th class="text-right">Current Balance (₹)</th>
          <th class="text-right">Interest Rate %</th>
          <th class="text-right">This FY Interest</th>
          <th class="text-right">Last Updated</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="savBody">
        <tr><td colspan="9" class="text-center" style="padding:40px;color:var(--text-muted)"><span class="spinner"></span></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Interest History -->
<div class="card" style="margin-top:24px">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
    <h3 class="card-title">Interest Credit History</h3>
    <select class="form-select" id="intFilterAccount" style="width:200px" onchange="SAV.loadInterestHistory()">
      <option value="">All Accounts</option>
    </select>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Bank</th>
          <th class="text-right">Interest Amount</th>
          <th class="text-right">Balance After</th>
          <th>FY</th>
          <th>Notes</th>
          <th class="text-center">Del</th>
        </tr>
      </thead>
      <tbody id="savIntBody">
        <tr><td colspan="7" class="text-center" style="padding:40px;color:var(--text-muted)"><span class="spinner"></span></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Account Modal -->
<div class="modal-overlay" id="modalAddAccount" style="display:none">
  <div class="modal" style="max-width:500px">
    <div class="modal-header">
      <h3 class="modal-title">Add Bank Account</h3>
      <button class="modal-close" id="closeAcctModal">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Portfolio *</label>
        <select class="form-select" id="savPortfolio">
          <?php foreach ($portfolios as $p): ?>
          <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Bank Name *</label>
          <input type="text" class="form-input" id="savBankName" placeholder="SBI, HDFC..." list="savBankList">
          <datalist id="savBankList">
            <option>SBI</option><option>HDFC Bank</option><option>ICICI Bank</option>
            <option>Axis Bank</option><option>Kotak Bank</option><option>PNB</option>
          </datalist>
        </div>
        <div class="form-group">
          <label class="form-label">Account Type *</label>
          <select class="form-select" id="savAcctType">
            <option value="savings">Savings</option>
            <option value="current">Current</option>
            <option value="salary">Salary</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Account Number</label>
          <input type="text" class="form-input" id="savAccountNum" placeholder="XXXXXXXXXXXX (optional)">
        </div>
        <div class="form-group">
          <label class="form-label">Interest Rate (% p.a.)</label>
          <input type="number" class="form-input" id="savInterestRate" step="0.01" min="0" max="10" placeholder="3.50">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Current Balance (₹) *</label>
        <input type="number" class="form-input" id="savBalance" step="0.01" min="0" placeholder="50000">
      </div>
      <div class="form-group">
        <label class="form-label">Balance as of Date *</label>
        <input type="date" class="form-input" id="savBalanceDate" value="<?= date('Y-m-d') ?>">
      </div>
      <div id="savAcctError" class="form-error" style="display:none"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelAcct">Cancel</button>
      <button class="btn btn-primary" id="saveAcct">Save Account</button>
    </div>
  </div>
</div>

<!-- Record Interest Modal -->
<div class="modal-overlay" id="modalAddInterest" style="display:none">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <h3 class="modal-title">Record Interest Credit</h3>
      <button class="modal-close" id="closeIntModal">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Account *</label>
        <select class="form-select" id="intAccountId"></select>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Date *</label>
          <input type="date" class="form-input" id="intDate" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Interest Amount (₹) *</label>
          <input type="number" class="form-input" id="intAmount" step="0.01" min="0.01" placeholder="500">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Balance After Credit (₹)</label>
        <input type="number" class="form-input" id="intBalanceAfter" step="0.01" min="0" placeholder="Auto-update account balance">
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" class="form-input" id="intNotes" placeholder="e.g. Q4 interest">
      </div>
      <div id="intError" class="form-error" style="display:none"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelInt">Cancel</button>
      <button class="btn btn-primary" id="saveInt">Record Interest</button>
    </div>
  </div>
</div>

<!-- Delete Confirm -->
<div class="modal-overlay" id="modalDelSav" style="display:none">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3 class="modal-title">Delete?</h3><button class="modal-close" id="closeDelSav">✕</button></div>
    <div class="modal-body"><p id="delSavMsg">Are you sure?</p></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelDelSav">Cancel</button>
      <button class="btn btn-danger" id="confirmDelSav">Delete</button>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/public/js/savings.js?v=<?= ASSET_VERSION ?>"></script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';

