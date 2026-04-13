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

// t423: Bank-wise diversification data (DICGC ₹5L per bank limit)
$divStmt = $db->prepare("
    SELECT fa.bank_name,
           SUM(fa.principal) AS total_principal,
           SUM(fa.maturity_amount) AS total_maturity,
           COUNT(*) AS fd_count
    FROM fd_accounts fa
    JOIN portfolios p ON p.id = fa.portfolio_id
    WHERE p.user_id = ? AND fa.status = 'active'
    GROUP BY fa.bank_name
    ORDER BY total_principal DESC
");
$divStmt->execute([$currentUser['id']]);
$bankDiversification = $divStmt->fetchAll();
$dicgcLimit    = 500000; // ₹5,00,000
$overLimitBanks = array_filter($bankDiversification, fn($b) => (float)$b['total_principal'] > $dicgcLimit);

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

<?php if (!empty($bankDiversification)): ?>
<!-- t423: FD Portfolio Diversification — DICGC Limit Check -->
<div class="card" style="margin-bottom:24px" id="fdDivCard">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;cursor:pointer" onclick="fdToggleDiv()">
    <h3 class="card-title">
      🏦 FD Portfolio Diversification
      <?php if (count($overLimitBanks) > 0): ?>
        <span style="display:inline-block;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:20px;font-size:11px;font-weight:700;padding:2px 10px;margin-left:8px;vertical-align:middle">
          ⚠ <?= count($overLimitBanks) ?> bank<?= count($overLimitBanks)>1?'s':'' ?> exceed DICGC limit
        </span>
      <?php else: ?>
        <span style="display:inline-block;background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;border-radius:20px;font-size:11px;font-weight:700;padding:2px 10px;margin-left:8px;vertical-align:middle">
          ✓ Well Diversified
        </span>
      <?php endif; ?>
    </h3>
    <span id="fdDivToggleIcon" style="color:var(--text-muted);font-size:18px;user-select:none">▾</span>
  </div>
  <div id="fdDivBody">
    <?php if (count($overLimitBanks) > 0): ?>
    <div style="background:#fef2f2;border-bottom:1px solid #fca5a5;padding:10px 16px;display:flex;align-items:center;gap:10px">
      <span style="font-size:18px">🚨</span>
      <div>
        <strong style="color:#b91c1c;font-size:13px">DICGC Insurance Limit Exceeded!</strong>
        <p style="margin:2px 0 0;font-size:12px;color:#7f1d1d">
          DICGC insures only <strong>₹5,00,000 per bank per depositor</strong>.
          Amount above ₹5L is <strong>at risk</strong> if the bank fails.
          Consider spreading your FDs across multiple banks.
        </p>
      </div>
    </div>
    <?php else: ?>
    <div style="background:#f0fdf4;border-bottom:1px solid #6ee7b7;padding:10px 16px;display:flex;align-items:center;gap:10px">
      <span style="font-size:18px">✅</span>
      <p style="margin:0;font-size:13px;color:#065f46">
        All your FDs are within the <strong>DICGC ₹5L per bank insurance limit</strong>. Your deposits are fully covered.
      </p>
    </div>
    <?php endif; ?>

    <!-- Bank concentration chart + table -->
    <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

      <!-- Left: Visual bar chart -->
      <div>
        <p style="margin:0 0 12px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Bank-wise Principal</p>
        <?php
          $maxPrincipal = max(array_column($bankDiversification, 'total_principal'));
          $colors = ['#4f46e5','#7c3aed','#2563eb','#0f766e','#b45309','#be123c','#15803d','#9333ea'];
          $ci = 0;
          foreach ($bankDiversification as $bank):
            $pct     = $maxPrincipal > 0 ? round((float)$bank['total_principal'] / $maxPrincipal * 100, 1) : 0;
            $sharePct= $totalPrincipal > 0 ? round((float)$bank['total_principal'] / $totalPrincipal * 100, 1) : 0;
            $overLimit = (float)$bank['total_principal'] > $dicgcLimit;
            $barColor  = $overLimit ? '#dc2626' : ($colors[$ci % count($colors)]);
        ?>
        <div style="margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px">
            <span style="font-size:13px;font-weight:600;color:var(--text-primary)">
              <?= htmlspecialchars($bank['bank_name'], ENT_QUOTES) ?>
              <?= $overLimit ? '<span style="color:#dc2626;font-size:11px;margin-left:4px">⚠ >₹5L</span>' : '' ?>
            </span>
            <span style="font-size:12px;color:var(--text-muted)"><?= $sharePct ?>% &nbsp;·&nbsp; <?= inr((float)$bank['total_principal']) ?></span>
          </div>
          <div style="background:var(--border,#e5e7eb);border-radius:99px;height:8px;overflow:hidden">
            <div style="width:<?= $pct ?>%;background:<?= $barColor ?>;height:100%;border-radius:99px;transition:width .5s"></div>
          </div>
          <?php if ($overLimit): ?>
          <div style="font-size:11px;color:#dc2626;margin-top:2px">
            ₹<?= number_format((float)$bank['total_principal'] - $dicgcLimit) ?> above DICGC limit (uninsured)
          </div>
          <?php endif; ?>
        </div>
        <?php $ci++; endforeach; ?>
      </div>

      <!-- Right: Summary + Recommendations -->
      <div>
        <p style="margin:0 0 12px;font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Concentration Summary</p>
        <div style="background:var(--surface-hover,#f9fafb);border:1px solid var(--border);border-radius:8px;overflow:hidden">
          <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
              <tr style="background:var(--border,#e5e7eb)">
                <th style="padding:8px 12px;text-align:left;font-weight:700">Bank</th>
                <th style="padding:8px 12px;text-align:right;font-weight:700">FDs</th>
                <th style="padding:8px 12px;text-align:right;font-weight:700">Principal</th>
                <th style="padding:8px 12px;text-align:right;font-weight:700">Share</th>
                <th style="padding:8px 12px;text-align:center;font-weight:700">DICGC</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($bankDiversification as $bank):
                $sharePct  = $totalPrincipal > 0 ? round((float)$bank['total_principal'] / $totalPrincipal * 100, 1) : 0;
                $overLimit = (float)$bank['total_principal'] > $dicgcLimit;
              ?>
              <tr style="border-top:1px solid var(--border,#e5e7eb)">
                <td style="padding:8px 12px;font-weight:600"><?= htmlspecialchars($bank['bank_name'], ENT_QUOTES) ?></td>
                <td style="padding:8px 12px;text-align:right;color:var(--text-muted)"><?= $bank['fd_count'] ?></td>
                <td style="padding:8px 12px;text-align:right"><?= inr((float)$bank['total_principal']) ?></td>
                <td style="padding:8px 12px;text-align:right;color:var(--text-muted)"><?= $sharePct ?>%</td>
                <td style="padding:8px 12px;text-align:center">
                  <?php if ($overLimit): ?>
                    <span style="background:#fee2e2;color:#b91c1c;border-radius:99px;padding:2px 8px;font-size:11px;font-weight:700">⚠ Exceeds</span>
                  <?php else: ?>
                    <span style="background:#d1fae5;color:#065f46;border-radius:99px;padding:2px 8px;font-size:11px;font-weight:700">✓ Safe</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Recommendation box -->
        <div style="margin-top:14px;background:var(--surface-hover,#f9fafb);border:1px solid var(--border);border-radius:8px;padding:12px 14px">
          <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:var(--text-primary)">💡 Recommendations</p>
          <ul style="margin:0;padding-left:16px;font-size:12px;color:var(--text-muted);line-height:1.7">
            <?php if (count($bankDiversification) < 3): ?>
            <li>Spread FDs across <strong>3+ banks</strong> to reduce concentration risk.</li>
            <?php endif; ?>
            <?php if (count($overLimitBanks) > 0): ?>
            <li>Move excess principal from highlighted banks to other banks to stay within the DICGC ₹5L safety net.</li>
            <li>Consider <strong>Small Finance Banks</strong> — they offer higher rates (7.5–9%) and are also DICGC-insured up to ₹5L.</li>
            <?php else: ?>
            <li>Your FD portfolio is well-structured. Keep maintaining &lt;₹5L per bank.</li>
            <?php endif; ?>
            <li><strong>Post Office FDs</strong> are backed by the Government of India — no deposit limit risk.</li>
            <li>Review FD concentration every quarter as balances grow with interest.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function fdToggleDiv() {
  const body = document.getElementById('fdDivBody');
  const icon = document.getElementById('fdDivToggleIcon');
  if (!body) return;
  const isHidden = body.style.display === 'none';
  body.style.display = isHidden ? '' : 'none';
  icon.textContent = isHidden ? '▾' : '▸';
}
</script>
<?php endif; ?>

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
    <div class="mf-view-toggle">
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
  <div id="fdPagWrap" style="padding:0 16px;"></div>
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