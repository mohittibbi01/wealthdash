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

<!-- t421: Page-level tab switcher -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:20px">
  <button id="fdTabMyFDs" onclick="fdSwitchTab('my_fds')"
    style="padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid var(--primary);margin-bottom:-2px;color:var(--primary)">
    📋 My FDs
  </button>
  <button id="fdTabRates" onclick="fdSwitchTab('rate_tracker')"
    style="padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-muted)">
    📊 Rate Tracker
  </button>
  <button id="fdTabRenewal" onclick="fdSwitchTab('renewal')"
    style="padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-muted)">
    🔄 Renewal Tool
  </button>
  <button id="fdTabLadder" onclick="fdSwitchTab('ladder')"
    style="padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-muted)">
    🪜 Ladder
  </button>
</div>

<!-- ─── MY FDs PANEL ──────────────────────────────────────── -->
<div id="fdPanelMyFDs">

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

<!-- t421: Page-level tab switcher: My FDs | Rate Tracker -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:20px" id="fdPageTabs">
  <button onclick="fdSwitchTab('my_fds')" id="fdTabMyFDs"
    style="padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid var(--primary);margin-bottom:-2px;color:var(--primary)">
    📋 My FDs
  </button>
  <button onclick="fdSwitchTab('rate_tracker')" id="fdTabRates"
    style="padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-muted)">
    📊 Rate Tracker
  </button>
  <button onclick="fdSwitchTab('interest_payout')" id="fdTabInterest2"
    style="padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-muted)">
    💰 Interest Tracker
  </button>
  <button onclick="fdSwitchTab('renewal')" id="fdTabRenewal2"
    style="padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-muted)">
    🔄 Renewal Tool
  </button>
  <button onclick="fdSwitchTab('ladder')" id="fdTabLadder2"
    style="padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;color:var(--text-muted)">
    🪜 Ladder
  </button>
</div>

<!-- ─── MY FDs PANEL ──────────────────────────────────────── -->
<div id="fdPanelMyFDs">

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

</div><!-- /fdPanelMyFDs -->

<!-- ─── RATE TRACKER PANEL (t421) ────────────────────────── -->
<div id="fdPanelRates" style="display:none">

  <div id="rateTrackerSummary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:20px"></div>

  <div class="card" style="margin-bottom:20px">
    <div class="card-header" style="display:flex;gap:0;padding-bottom:0;border-bottom:1px solid var(--border)">
      <button onclick="RateTracker.subTab('grid')" id="rtSubGrid"
        class="rt-sub"
        style="padding:10px 18px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid var(--primary);margin-bottom:-1px;color:var(--primary)">
        Bank Rate Grid
      </button>
      <button onclick="RateTracker.subTab('compare')" id="rtSubCompare"
        class="rt-sub"
        style="padding:10px 18px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;color:var(--text-muted)">
        Compare My FDs
      </button>
      <button onclick="RateTracker.subTab('opportunities')" id="rtSubOpp"
        class="rt-sub"
        style="padding:10px 18px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;color:var(--text-muted)">
        Opportunities
        <span id="rtOppBadge" style="display:none;background:#dc2626;color:#fff;border-radius:99px;font-size:10px;padding:1px 6px;margin-left:4px"></span>
      </button>
    </div>
    <div style="padding:6px 16px 12px">

      <!-- Rate Grid sub-panel -->
      <div id="rtPanelGrid">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:12px 0 8px">
          <select id="rtFilterType" onchange="RateTracker.filterGrid()"
            style="padding:6px 10px;border-radius:6px;border:1px solid var(--border);background:var(--bg-secondary);color:var(--text-primary);font-size:12px">
            <option value="">All Bank Types</option>
            <option value="small_finance">Small Finance Banks 🔥</option>
            <option value="private">Private Banks</option>
            <option value="private_large">Large Private Banks</option>
            <option value="public">PSU Banks</option>
            <option value="government">Govt. Schemes</option>
          </select>
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);cursor:pointer">
            <input type="checkbox" id="rtSeniorToggle" onchange="RateTracker.filterGrid()" style="cursor:pointer">
            Senior Citizen Rates
          </label>
          <span style="margin-left:auto;font-size:11px;color:var(--text-muted)" id="rtLastUpdated"></span>
        </div>
        <div style="overflow-x:auto">
          <table style="width:100%;border-collapse:collapse;font-size:12px" id="rtGridTable">
            <thead id="rtGridHead"></thead>
            <tbody id="rtGridBody">
              <tr><td colspan="10" style="padding:32px;text-align:center;color:var(--text-muted)">
                <span class="spinner"></span> Loading rates…
              </td></tr>
            </tbody>
          </table>
        </div>
        <p style="font-size:11px;color:var(--text-muted);margin:8px 0 0;padding-top:8px;border-top:1px solid var(--border)">
          ⚠ Rates are indicative and updated periodically. DICGC insures up to ₹5L per bank per depositor. Always verify with the bank before investing.
        </p>
      </div>

      <!-- Compare sub-panel -->
      <div id="rtPanelCompare" style="display:none">
        <div id="rtCompareBody" style="padding:12px 0">
          <div style="text-align:center;padding:32px;color:var(--text-muted)"><span class="spinner"></span> Comparing your FDs with market…</div>
        </div>
      </div>

      <!-- Opportunities sub-panel -->
      <div id="rtPanelOpp" style="display:none">
        <div id="rtOppBody" style="padding:12px 0">
          <div style="text-align:center;padding:32px;color:var(--text-muted)"><span class="spinner"></span> Finding renewal opportunities…</div>
        </div>
      </div>

    </div>
  </div>
</div><!-- /fdPanelRates -->

<!-- ─── t424: INTEREST PAYOUT TRACKER PANEL ──────────────── -->
<div id="fdPanelInterest" style="display:none">

<!-- Summary Strip -->
<div class="stats-grid" style="margin-bottom:20px" id="iptSummaryStrip">
  <div class="stat-card">
    <div class="stat-label">FD Interest This FY</div>
    <div class="stat-value text-success" id="iptFdFy">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Savings Interest This FY</div>
    <div class="stat-value" id="iptSavFy">—</div>
    <div class="stat-sub" id="iptSavNote" style="font-size:11px"></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">TDS Deducted (FY)</div>
    <div class="stat-value text-danger" id="iptTdsFy">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Interest Income</div>
    <div class="stat-value" id="iptTotal">—</div>
  </div>
</div>

<!-- 80TTA Alert -->
<div id="ipt80ttaAlert" style="display:none;margin-bottom:16px;padding:12px 16px;border-radius:10px;border-left:4px solid;font-size:13px"></div>

<!-- Form 15G/H Reminder -->
<div id="ipt15GAlert" style="display:none;margin-bottom:16px;padding:12px 16px;background:rgba(251,191,36,.08);border:1.5px solid #fbbf24;border-radius:10px;font-size:13px">
  📋 <strong>Form 15G/H Reminder:</strong> <span id="ipt15GText"></span>
</div>

<!-- Monthly Calendar -->
<div class="card mb-4">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
    <h3 class="card-title">📅 Interest Income Calendar</h3>
    <div style="display:flex;gap:8px;align-items:center">
      <select id="iptFySelect" class="form-input" style="width:120px;padding:6px 10px" onchange="IPT.load()">
        <?php
        $curYear = (int)date('Y');
        $curMon  = (int)date('n');
        $fyStart = $curMon >= 4 ? $curYear : $curYear - 1;
        for ($y = $fyStart; $y >= $fyStart - 3; $y--) {
            $label = $y . '-' . substr($y+1, 2);
            $selected = ($y === $fyStart) ? 'selected' : '';
            echo "<option value="$y" $selected>FY $label</option>";
        }
        ?>
      </select>
    </div>
  </div>
  <div class="card-body">
    <div id="iptCalLoading" style="text-align:center;padding:32px;color:var(--text-muted)">Loading...</div>
    <div id="iptCalGrid" style="display:none;display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px"></div>
  </div>
</div>

<!-- FD-wise Interest Table -->
<div class="card mb-4">
  <div class="card-header"><h3 class="card-title">🏦 FD-wise Interest Breakdown</h3></div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Bank</th><th>Principal</th><th>Rate</th><th>Start Date</th>
          <th class="text-right">Interest Accrued (FY)</th>
          <th class="text-right">TDS @ 10%</th>
          <th class="text-right">Net Interest</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody id="iptFdTable"><tr><td colspan="8" class="text-center text-muted">Loading...</td></tr></tbody>
    </table>
  </div>
</div>

<!-- Month-wise bar chart -->
<div class="card mb-4">
  <div class="card-header"><h3 class="card-title">📊 Monthly Interest Income</h3></div>
  <div class="card-body" style="height:240px;position:relative">
    <canvas id="iptMonthChart"></canvas>
  </div>
</div>


<!-- ─── t425: FD RENEWAL DECISION TOOL PANEL ──────────────── -->
<div id="fdPanelRenewal" style="display:none">

<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">🔄 FD Renewal Decision Tool</h3>
    <p style="margin:4px 0 0;font-size:13px;color:var(--text-muted)">Maturing FDs ke liye — Renew, Redeem, ya Partial Redeem?</p>
  </div>
  <div class="card-body">

    <!-- FD Selector -->
    <div style="margin-bottom:20px">
      <label style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:6px">Select FD to Analyze</label>
      <select id="rdtFdSelect" class="form-input" style="max-width:420px" onchange="RDT.onFdSelect()">
        <option value="">— Choose an FD —</option>
      </select>
    </div>

    <div id="rdtMain" style="display:none">

      <!-- Current FD Summary -->
      <div id="rdtSummaryStrip" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px"></div>

      <!-- Three Options Cards -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:24px">

        <!-- Option A: Renew -->
        <div class="card" style="border-top:3px solid #2563eb">
          <div class="card-body">
            <div style="font-size:13px;font-weight:700;color:#2563eb;margin-bottom:10px">🔁 Option A — Renew</div>
            <div style="margin-bottom:8px">
              <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">New Rate (% p.a.)</label>
              <input type="number" id="rdtRenewRate" class="form-input" style="width:100%" step="0.05" placeholder="e.g. 7.5" oninput="RDT.compute()">
            </div>
            <div style="margin-bottom:8px">
              <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">Tenure (months)</label>
              <input type="number" id="rdtRenewTenure" class="form-input" style="width:100%" placeholder="12" oninput="RDT.compute()">
            </div>
            <div id="rdtRenewResult" style="margin-top:12px;padding:10px;background:rgba(37,99,235,.07);border-radius:8px;display:none">
              <div style="font-size:11px;color:var(--text-muted)">Maturity Value</div>
              <div style="font-size:20px;font-weight:800;color:#2563eb" id="rdtRenewMaturity">—</div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Interest earned: <strong id="rdtRenewInterest">—</strong></div>
              <div style="font-size:11px;color:var(--text-muted)">Annualized: <strong id="rdtRenewXirr">—</strong></div>
            </div>
          </div>
        </div>

        <!-- Option B: Partial Redeem -->
        <div class="card" style="border-top:3px solid #d97706">
          <div class="card-body">
            <div style="font-size:13px;font-weight:700;color:#d97706;margin-bottom:10px">💸 Option B — Partial Redeem</div>
            <div style="margin-bottom:8px">
              <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">Amount to Redeem (₹)</label>
              <input type="number" id="rdtPartialAmt" class="form-input" style="width:100%" placeholder="e.g. 100000" oninput="RDT.compute()">
            </div>
            <div style="margin-bottom:8px">
              <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">Purpose</label>
              <select id="rdtPartialPurpose" class="form-input" style="width:100%">
                <option value="expense">Immediate Expense</option>
                <option value="invest">Reinvest Elsewhere</option>
                <option value="emergency">Emergency Fund</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div id="rdtPartialResult" style="margin-top:12px;padding:10px;background:rgba(217,119,6,.07);border-radius:8px;display:none">
              <div style="font-size:11px;color:var(--text-muted)">Amount Available (after TDS)</div>
              <div style="font-size:20px;font-weight:800;color:#d97706" id="rdtPartialNet">—</div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Remaining FD renewed at current rate</div>
              <div style="font-size:13px;font-weight:600" id="rdtPartialRemainder">—</div>
            </div>
          </div>
        </div>

        <!-- Option C: Full Redeem -->
        <div class="card" style="border-top:3px solid #dc2626">
          <div class="card-body">
            <div style="font-size:13px;font-weight:700;color:#dc2626;margin-bottom:10px">🏧 Option C — Full Redeem</div>
            <div style="margin-bottom:8px">
              <label style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:3px">Redeploy to</label>
              <select id="rdtRedeployTo" class="form-input" style="width:100%" onchange="RDT.compute()">
                <option value="savings">Savings Account (4%)</option>
                <option value="liquid">Liquid Fund (~7%)</option>
                <option value="debt">Short-term Debt Fund (~7.5%)</option>
                <option value="mf_equity">Equity MF (12% assumed)</option>
                <option value="spend">Spend / No Reinvestment</option>
              </select>
            </div>
            <div id="rdtRedeemResult" style="margin-top:12px;padding:10px;background:rgba(220,38,38,.07);border-radius:8px">
              <div style="font-size:11px;color:var(--text-muted)">Maturity Amount Received</div>
              <div style="font-size:20px;font-weight:800;color:#dc2626" id="rdtRedeemAmt">—</div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px" id="rdtRedeemNote">—</div>
              <div style="font-size:13px;font-weight:600;margin-top:6px" id="rdtRedeemProjection">—</div>
            </div>
          </div>
        </div>

      </div><!-- /3 options grid -->

      <!-- Decision Matrix -->
      <div class="card mb-4" id="rdtDecisionCard" style="display:none">
        <div class="card-header"><h3 class="card-title">⚖️ Decision Matrix</h3></div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;border:1.5px solid var(--border);border-radius:10px;overflow:hidden" id="rdtMatrix"></div>
          <div id="rdtVerdict" style="margin-top:16px;padding:14px 18px;border-radius:10px;font-size:14px;font-weight:600"></div>
        </div>
      </div>

      <!-- Opportunity Cost Chart -->
      <div class="card mb-4">
        <div class="card-header"><h3 class="card-title">📈 Opportunity Cost — 1-Year Comparison</h3></div>
        <div class="card-body" style="height:220px;position:relative">
          <canvas id="rdtOppChart"></canvas>
        </div>
      </div>

    </div><!-- /rdtMain -->
    <div id="rdtEmpty" style="text-align:center;padding:40px;color:var(--text-muted)">
      👆 Select an FD above to see renewal analysis
    </div>

  </div>
</div>

</div><!-- /fdPanelRenewal -->

<script>
/* ── t425: FD Renewal Decision Tool ──────────────────────────── */
const RDT = (() => {
  let _fds    = [];
  let _selFd  = null;
  let _chart  = null;
  const INR   = v => '₹' + Number(v||0).toLocaleString('en-IN', {maximumFractionDigits:0});
  const portId = () => window.WD?.selectedPortfolio || '';

  // ── Load FDs into selector ───────────────────────────────────
  async function loadFdList() {
    try {
      const res = await window.apiPost({ action:'fd_list', portfolio_id: portId(), status: 'all', per_page: 200 });
      if (!res.success) return;
      _fds = (res.data?.fds || res.data?.rows || []).filter(f => f.status === 'active' || f.status === 'matured');
      const sel = document.getElementById('rdtFdSelect');
      if (!sel) return;
      sel.innerHTML = '<option value="">— Choose an FD —</option>' +
        _fds.map(f => `<option value="${f.id}">
          ${f.bank_name} — ₹${Number(f.principal_amount||f.principal||0).toLocaleString('en-IN',{maximumFractionDigits:0})} @ ${f.interest_rate}% — Matures ${f.maturity_date}
        </option>`).join('');
    } catch(e) { console.warn('RDT: FD list load failed', e); }
  }

  function onFdSelect() {
    const sel = document.getElementById('rdtFdSelect');
    const id  = parseInt(sel.value);
    _selFd = _fds.find(f => f.id == id) || null;

    const main  = document.getElementById('rdtMain');
    const empty = document.getElementById('rdtEmpty');
    if (!_selFd) { main.style.display='none'; empty.style.display=''; return; }
    main.style.display  = '';
    empty.style.display = 'none';

    renderSummary(_selFd);
    // Pre-fill renewal rate same as original
    document.getElementById('rdtRenewRate').value   = _selFd.interest_rate || '';
    document.getElementById('rdtRenewTenure').value = '12';
    compute();
  }

  function renderSummary(fd) {
    const p   = parseFloat(fd.principal_amount || fd.principal || 0);
    const mat = parseFloat(fd.maturity_amount  || 0);
    const int = mat - p;
    const daysLeft = Math.max(0, Math.round((new Date(fd.maturity_date) - new Date()) / 86400000));

    document.getElementById('rdtSummaryStrip').innerHTML = [
      { label: 'Principal',       val: INR(p) },
      { label: 'Maturity Amount', val: INR(mat), color: '#16a34a' },
      { label: 'Interest',        val: INR(int), color: '#16a34a' },
      { label: 'Rate',            val: fd.interest_rate + '% p.a.' },
      { label: 'Matures',        val: fd.maturity_date },
      { label: 'Days Left',       val: daysLeft + ' days', color: daysLeft <= 30 ? '#dc2626' : undefined },
    ].map(s => `<div style="background:var(--bg-secondary);border-radius:10px;padding:10px 16px;min-width:110px;text-align:center">
      <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px">${s.label}</div>
      <div style="font-size:14px;font-weight:800;color:${s.color||'var(--text-primary)'}">${s.val}</div>
    </div>`).join('');
  }

  function compute() {
    if (!_selFd) return;
    const p        = parseFloat(_selFd.principal_amount || _selFd.principal || 0);
    const matAmt   = parseFloat(_selFd.maturity_amount || p);
    const newRate  = parseFloat(document.getElementById('rdtRenewRate').value || 0);
    const tenure   = parseInt(document.getElementById('rdtRenewTenure').value || 12);
    const partAmt  = parseFloat(document.getElementById('rdtPartialAmt').value || 0);
    const redeployTo = document.getElementById('rdtRedeployTo').value;

    // ── Option A: Renew ──────────────────────────────────────
    const renewMaturity = computeFdMaturity(matAmt, newRate, tenure);
    const renewInterest = renewMaturity - matAmt;
    const aRes = document.getElementById('rdtRenewResult');
    if (newRate > 0) {
      aRes.style.display = '';
      document.getElementById('rdtRenewMaturity').textContent = INR(renewMaturity);
      document.getElementById('rdtRenewInterest').textContent = INR(renewInterest);
      document.getElementById('rdtRenewXirr').textContent     = newRate.toFixed(2) + '% p.a.';
    } else {
      aRes.style.display = 'none';
    }

    // ── Option B: Partial Redeem ──────────────────────────────
    const bRes = document.getElementById('rdtPartialResult');
    if (partAmt > 0 && partAmt < matAmt) {
      bRes.style.display = '';
      const tds    = partAmt > 40000 ? partAmt * 0.1 : 0;
      const netPay = partAmt - tds;
      const remaining = matAmt - partAmt;
      const remMaturity = newRate > 0 ? computeFdMaturity(remaining, newRate, tenure) : remaining;
      document.getElementById('rdtPartialNet').textContent = INR(netPay) + (tds > 0 ? ` <span style="font-size:11px;color:#dc2626">(TDS: ${INR(tds)})</span>` : '');
      document.getElementById('rdtPartialRemainder').textContent = `Remaining ${INR(remaining)} → ${INR(remMaturity)} after ${tenure}m`;
    } else {
      bRes.style.display = 'none';
    }

    // ── Option C: Full Redeem ─────────────────────────────────
    const redeployRates = { savings: 4, liquid: 7, debt: 7.5, mf_equity: 12, spend: 0 };
    const redeployRate  = redeployRates[redeployTo] || 0;
    const tdsOnRedeem   = matAmt > 40000 ? matAmt * 0.1 : 0;
    const netRedeem     = matAmt - tdsOnRedeem;
    const redeemGrowth  = redeployRate > 0 ? computeFdMaturity(netRedeem, redeployRate, tenure) : netRedeem;

    document.getElementById('rdtRedeemAmt').textContent = INR(netRedeem) + (tdsOnRedeem > 0 ? ` <small style="color:#dc2626">(TDS: ${INR(tdsOnRedeem)})</small>` : '');
    document.getElementById('rdtRedeemNote').textContent = redeployTo === 'spend' ? 'No reinvestment' : `Reinvested @ ${redeployRate}% effective p.a.`;
    document.getElementById('rdtRedeemProjection').textContent = redeployRate > 0 ? `→ ${INR(redeemGrowth)} after ${tenure} months` : '';

    // ── Decision Matrix ───────────────────────────────────────
    renderDecisionMatrix(p, matAmt, renewMaturity, netRedeem, redeemGrowth, newRate, redeployRate, tenure);

    // ── Opportunity Cost Chart ────────────────────────────────
    renderOppChart(matAmt, newRate, redeployRate, tenure);
  }

  function computeFdMaturity(principal, rate, months) {
    // Quarterly compounding
    const n = months / 3;
    const r = rate / (4 * 100);
    return Math.round(principal * Math.pow(1 + r, n) * 100) / 100;
  }

  function renderDecisionMatrix(p, matAmt, renewMat, netRedeem, redeemGrowth, renewRate, redeployRate, tenure) {
    const card   = document.getElementById('rdtDecisionCard');
    const matrix = document.getElementById('rdtMatrix');
    const verdict = document.getElementById('rdtVerdict');

    const rows = [
      { criterion: 'Returns After ' + tenure + 'M', a: renewMat, b: (renewMat + netRedeem)/2, c: redeemGrowth, higher: true },
    ];

    const criteria = [
      { label: 'Returns (projected)',   a: renewMat,       c: redeemGrowth,  higher: true  },
      { label: 'Liquidity',             a: 2,              c: 5,             higher: true  },
      { label: 'Tax Efficiency',        a: renewRate>7?3:4, c: redeployRate>0?4:2, higher: true  },
      { label: 'Risk Level',            a: 5,              c: redeployRate>=12?2:4, higher: true  },
    ];

    const headers = ['<div style="padding:10px 14px;background:var(--bg-secondary);font-weight:700;font-size:11px;text-transform:uppercase;color:var(--text-muted)">Criterion</div>',
      '<div style="padding:10px 14px;background:rgba(37,99,235,.08);font-weight:700;font-size:12px;color:#2563eb;text-align:center">🔁 Renew</div>',
      '<div style="padding:10px 14px;background:rgba(220,38,38,.08);font-weight:700;font-size:12px;color:#dc2626;text-align:center">🏧 Redeem & Redeploy</div>',
    ];

    let aScore = 0, cScore = 0;
    const dataRows = criteria.map(cr => {
      const aBetter = cr.higher ? cr.a >= cr.c : cr.a <= cr.c;
      if (aBetter) aScore++; else cScore++;
      const aStyle = aBetter ? 'color:#16a34a;font-weight:700' : 'color:var(--text-muted)';
      const cStyle = !aBetter ? 'color:#16a34a;font-weight:700' : 'color:var(--text-muted)';
      const aVal = cr.label === 'Returns (projected)' ? INR(cr.a) : ['Low','Med-Low','Med','Med-High','High'][cr.a-1]||cr.a;
      const cVal = cr.label === 'Returns (projected)' ? INR(cr.c) : ['Low','Med-Low','Med','Med-High','High'][cr.c-1]||cr.c;
      return `<div style="padding:10px 14px;border-top:1px solid var(--border);font-size:13px">${cr.label}</div>
        <div style="padding:10px 14px;border-top:1px solid var(--border);text-align:center;font-size:13px;${aStyle}">${aVal} ${aBetter?'✓':''}</div>
        <div style="padding:10px 14px;border-top:1px solid var(--border);text-align:center;font-size:13px;${cStyle}">${cVal} ${!aBetter?'✓':''}</div>`;
    });

    matrix.innerHTML = headers.join('') + dataRows.join('');
    card.style.display = '';

    const winner = aScore > cScore ? 'renew' : cScore > aScore ? 'redeem' : 'tie';
    const verdictMap = {
      renew:  { bg: 'rgba(37,99,235,.08)', border: '#2563eb', color: '#2563eb', icon: '🔁', text: `Recommendation: <strong>Renew the FD</strong> — Score ${aScore}:${cScore} in favor. Higher returns with capital safety.` },
      redeem: { bg: 'rgba(220,38,38,.08)', border: '#dc2626', color: '#dc2626', icon: '🏧', text: `Recommendation: <strong>Redeem & Redeploy</strong> — Score ${cScore}:${aScore} in favor. Better opportunity with ${document.getElementById('rdtRedeployTo').options[document.getElementById('rdtRedeployTo').selectedIndex].text}.` },
      tie:    { bg: 'rgba(100,100,100,.08)', border: 'var(--border)', color: 'var(--text-primary)', icon: '⚖️', text: 'Scores are tied — decision depends on your liquidity needs and risk appetite.' },
    };
    const v = verdictMap[winner];
    verdict.style.cssText = `background:${v.bg};border:1.5px solid ${v.border};border-radius:10px;padding:14px 18px;color:${v.color};font-size:14px`;
    verdict.innerHTML = `${v.icon} ${v.text}`;
  }

  function renderOppChart(matAmt, renewRate, redeployRate, tenure) {
    const canvas = document.getElementById('rdtOppChart');
    if (!canvas) return;
    if (_chart) { _chart.destroy(); _chart = null; }

    const labels = [];
    const renewVals = [], redeemVals = [], labels2 = [];
    for (let m = 0; m <= tenure; m += Math.ceil(tenure/12)) {
      labels.push(m + 'M');
      renewVals.push(Math.round(computeFdMaturity(matAmt, renewRate || 0, m)));
      redeemVals.push(Math.round(computeFdMaturity(matAmt, redeployRate || 0, m)));
    }

    _chart = new Chart(canvas, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: '🔁 Renew', data: renewVals, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.08)', fill: true, tension: 0.3, borderWidth: 2.5, pointRadius: 3 },
          { label: '🏧 Redeploy', data: redeemVals, borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,.06)', fill: true, tension: 0.3, borderWidth: 2, borderDash: [5,3], pointRadius: 3 },
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { font: { size: 11 } } },
          tooltip: { callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ₹' + ctx.raw.toLocaleString('en-IN', {maximumFractionDigits:0}) } }
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 11 } } },
          y: { ticks: { font: { size: 11 }, callback: v => v>=100000?'₹'+(v/100000).toFixed(1)+'L':'₹'+v.toLocaleString('en-IN') } }
        }
      }
    });
  }

  function init() {
    if (_fds.length === 0) loadFdList();
  }

  return { init, onFdSelect, compute, loadFdList };
})();
</script>

</div><!-- /fdPanelInterest -->

<script>
/* ── t424: Interest Payout Tracker ──────────────────────────── */
const IPT = (() => {
  let _chart = null;
  const INR = v => '₹' + Number(v||0).toLocaleString('en-IN', {maximumFractionDigits:0});
  const portId = () => window.WD?.selectedPortfolio || '';

  async function load() {
    const fy = document.getElementById('iptFySelect')?.value || new Date().getFullYear();
    document.getElementById('iptCalLoading').style.display = '';
    document.getElementById('iptCalGrid').style.display = 'none';

    try {
      const res = await window.apiPost({ action:'fd_interest_tracker', portfolio_id: portId(), fy_start: fy });
      if (!res.success) { showErr(res.message); return; }
      const d = res.data;
      renderSummary(d.summary);
      renderAlerts(d.summary, d.fds);
      renderCalendar(d.monthly);
      renderFdTable(d.fds);
      renderChart(d.monthly);
    } catch(e) {
      console.error(e);
      showErr('Failed to load interest data');
    }
  }

  function renderSummary(s) {
    document.getElementById('iptFdFy').textContent   = INR(s.fd_interest_fy);
    document.getElementById('iptSavFy').textContent  = INR(s.sav_interest_fy);
    document.getElementById('iptTdsFy').textContent  = INR(s.tds_fy);
    document.getElementById('iptTotal').textContent  = INR(s.total_interest_fy);

    // 80TTA: ₹10K savings interest deduction
    const savInt = parseFloat(s.sav_interest_fy || 0);
    const noteEl = document.getElementById('iptSavNote');
    if (savInt > 0) {
      const exemptLeft = Math.max(0, 10000 - savInt);
      noteEl.textContent = savInt >= 10000 ? '⚠️ 80TTA limit reached' : `₹${(10000-savInt).toLocaleString('en-IN')} left under 80TTA`;
      noteEl.style.color = savInt >= 10000 ? '#dc2626' : '#16a34a';
    }
  }

  function renderAlerts(s, fds) {
    // 80TTA alert
    const ttaEl = document.getElementById('ipt80ttaAlert');
    const savInt = parseFloat(s.sav_interest_fy || 0);
    const totalIncome = parseFloat(s.total_interest_fy || 0);
    if (savInt > 0) {
      const used = Math.min(savInt, 10000);
      const pct  = Math.round(used / 10000 * 100);
      ttaEl.style.display = '';
      ttaEl.style.borderColor = savInt >= 10000 ? '#dc2626' : '#16a34a';
      ttaEl.style.background  = savInt >= 10000 ? 'rgba(220,38,38,.06)' : 'rgba(22,163,74,.06)';
      ttaEl.innerHTML = `<strong>80TTA — Savings Interest Deduction:</strong> ₹${used.toLocaleString('en-IN')} of ₹10,000 used (${pct}%)
        <div style="margin-top:8px;background:var(--bg-secondary);border-radius:6px;height:8px;overflow:hidden">
          <div style="width:${Math.min(pct,100)}%;height:100%;background:${savInt>=10000?'#dc2626':'#16a34a'};border-radius:6px;transition:width .4s"></div>
        </div>
        ${savInt > 10000 ? `<div style="margin-top:6px;color:#dc2626;font-size:12px">₹${(savInt-10000).toLocaleString('en-IN')} excess — taxable as per your slab</div>` : ''}`;
    } else {
      ttaEl.style.display = 'none';
    }

    // Form 15G/H reminder: if FD interest > ₹40K (₹50K for senior)
    const fdInt = parseFloat(s.fd_interest_fy || 0);
    const alertEl = document.getElementById('ipt15GAlert');
    const textEl  = document.getElementById('ipt15GText');
    if (fdInt > 0 && fdInt < 40000) {
      alertEl.style.display = '';
      textEl.textContent = `FD interest this FY is ${INR(fdInt)}. If total income is below ₹2.5L (₹3L senior), submit Form 15G (below 60) / 15H (60+) to your bank to avoid 10% TDS deduction.`;
    } else if (fdInt >= 40000) {
      alertEl.style.display = '';
      alertEl.style.background = 'rgba(239,68,68,.07)';
      alertEl.style.borderColor = '#ef4444';
      textEl.innerHTML = `FD interest <strong>${INR(fdInt)}</strong> exceeds ₹40,000 threshold. TDS @ 10% applies. Ensure Form 26AS reflects correct TDS credit. Verify TDS certificates (Form 16A) from your banks.`;
    } else {
      alertEl.style.display = 'none';
    }
  }

  function renderCalendar(monthly) {
    const gridEl = document.getElementById('iptCalGrid');
    const loadEl = document.getElementById('iptCalLoading');
    loadEl.style.display = 'none';
    gridEl.style.display = 'grid';

    if (!monthly || !monthly.length) {
      gridEl.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:20px;grid-column:1/-1">No interest data for this FY</div>';
      return;
    }

    const maxVal = Math.max(...monthly.map(m => parseFloat(m.total_interest || 0)));

    gridEl.innerHTML = monthly.map(m => {
      const val = parseFloat(m.total_interest || 0);
      const barH = maxVal > 0 ? Math.round(val / maxVal * 48) : 0;
      const isCurrentMonth = m.is_current;
      const isFuture = m.is_future;
      return `
        <div style="background:var(--bg-secondary);border-radius:12px;padding:14px;text-align:center;border:1.5px solid ${isCurrentMonth?'var(--primary)':'var(--border)'}">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px">${m.month_label}</div>
          <div style="height:50px;display:flex;align-items:flex-end;justify-content:center;margin-bottom:8px">
            <div style="width:28px;background:${isFuture?'rgba(59,130,246,.25)':val>0?'var(--primary)':'var(--border)'};border-radius:4px 4px 0 0;height:${barH||4}px;transition:height .3s"></div>
          </div>
          <div style="font-size:14px;font-weight:800;color:${isFuture?'var(--text-muted)':'var(--text-primary)'}">${val > 0 ? INR(val) : '—'}</div>
          ${isFuture ? '<div style="font-size:10px;color:var(--text-muted);margin-top:2px">Projected</div>' : ''}
        </div>`;
    }).join('');
  }

  function renderFdTable(fds) {
    const tbody = document.getElementById('iptFdTable');
    if (!fds || !fds.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No active FDs</td></tr>';
      return;
    }
    tbody.innerHTML = fds.map(f => {
      const fdInt = parseFloat(f.interest_accrued_fy || 0);
      const tds   = fdInt > 40000 ? fdInt * 0.1 : 0;
      const net   = fdInt - tds;
      return `<tr>
        <td><strong>${f.bank_name}</strong></td>
        <td>₹${Number(f.principal_amount).toLocaleString('en-IN', {maximumFractionDigits:0})}</td>
        <td>${f.interest_rate}%</td>
        <td>${f.start_date}</td>
        <td class="text-right text-success">₹${fdInt.toLocaleString('en-IN',{maximumFractionDigits:0})}</td>
        <td class="text-right ${tds>0?'text-danger':'text-muted'}">${tds>0?'₹'+tds.toLocaleString('en-IN',{maximumFractionDigits:0}):'—'}</td>
        <td class="text-right">₹${net.toLocaleString('en-IN',{maximumFractionDigits:0})}</td>
        <td><span class="badge ${f.status==='active'?'badge-success':'badge-secondary'}">${f.status}</span></td>
      </tr>`;
    }).join('');
  }

  function renderChart(monthly) {
    const canvas = document.getElementById('iptMonthChart');
    if (!canvas || !monthly) return;
    if (_chart) { _chart.destroy(); _chart = null; }
    const labels = monthly.map(m => m.month_label);
    const vals   = monthly.map(m => parseFloat(m.total_interest || 0));
    const colors = monthly.map(m => m.is_future ? 'rgba(59,130,246,.3)' : 'rgba(59,130,246,.85)');

    _chart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [{ label: 'Interest (₹)', data: vals, backgroundColor: colors, borderRadius: 5 }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => ' ₹' + ctx.raw.toLocaleString('en-IN', {maximumFractionDigits:0}) } }
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 11 } } },
          y: { ticks: { font: { size: 11 }, callback: v => v >= 100000 ? '₹' + (v/100000).toFixed(1)+'L' : '₹'+v.toLocaleString('en-IN') } }
        }
      }
    });
  }

  function showErr(msg) {
    document.getElementById('iptCalLoading').innerHTML = `<div style="color:#dc2626">${msg}</div>`;
  }

  return { load };
})();
</script>

<script>
/* ── t424+t425: Updated fdSwitchTab — 5 tabs (incl. t44 Ladder) ── */
function fdSwitchTab(tab) {
  const active = 'border-bottom:2px solid var(--primary);color:var(--primary)';
  const inact  = 'border-bottom:2px solid transparent;color:var(--text-muted)';
  const base   = 'padding:10px 22px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;margin-bottom:-2px;';

  const tabs    = ['fdTabMyFDs','fdTabRates','fdTabInterest','fdTabInterest2','fdTabRenewal','fdTabRenewal2','fdTabLadder','fdTabLadder2'];
  const panels  = ['fdPanelMyFDs','fdPanelRates','fdPanelInterest','fdPanelRenewal','fdPanelLadder'];

  tabs.forEach(id => { const el = document.getElementById(id); if(el) el.style.cssText = base + inact; });
  panels.forEach(id => { const el = document.getElementById(id); if(el) el.style.display = 'none'; });

  const activate = (tabIds, panelId) => {
    tabIds.forEach(id => { const el = document.getElementById(id); if(el) el.style.cssText = base + active; });
    const p = document.getElementById(panelId); if(p) p.style.display = '';
  };

  if (tab === 'my_fds') {
    activate(['fdTabMyFDs'], 'fdPanelMyFDs');
  } else if (tab === 'rate_tracker') {
    activate(['fdTabRates'], 'fdPanelRates');
    if(typeof RateTracker !== 'undefined') RateTracker.init();
  } else if (tab === 'interest_payout') {
    activate(['fdTabInterest','fdTabInterest2'], 'fdPanelInterest');
    if(typeof IPT !== 'undefined') IPT.load();
  } else if (tab === 'renewal') {
    activate(['fdTabRenewal','fdTabRenewal2'], 'fdPanelRenewal');
    if(typeof RDT !== 'undefined') RDT.init();
  } else if (tab === 'ladder') {
    activate(['fdTabLadder','fdTabLadder2'], 'fdPanelLadder');
    if(typeof FDLadder !== 'undefined') FDLadder.init();
  }
}
</script>

<!-- ─── t44: FD LADDERING VISUALIZATION PANEL ─────────────── -->
<div id="fdPanelLadder" style="display:none">

<!-- Score + Summary Strip -->
<div style="display:grid;grid-template-columns:auto 1fr;gap:20px;margin-bottom:24px;align-items:center">

  <!-- Ladder Score Gauge -->
  <div style="background:var(--bg-secondary);border:1.5px solid var(--border);border-radius:16px;padding:20px 28px;text-align:center;min-width:170px">
    <div id="ladderScoreRing" style="position:relative;width:96px;height:96px;margin:0 auto 10px">
      <svg width="96" height="96" viewBox="0 0 96 96">
        <circle cx="48" cy="48" r="40" fill="none" stroke="var(--border)" stroke-width="10"/>
        <circle id="ladderScoreArc" cx="48" cy="48" r="40" fill="none" stroke="#16a34a" stroke-width="10"
          stroke-linecap="round" stroke-dasharray="251.2" stroke-dashoffset="251.2"
          transform="rotate(-90 48 48)" style="transition:stroke-dashoffset 1s ease,stroke .4s"/>
      </svg>
      <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
        <div id="ladderScoreNum" style="font-size:24px;font-weight:900;line-height:1">—</div>
        <div style="font-size:9px;color:var(--text-muted);font-weight:700;text-transform:uppercase">Score</div>
      </div>
    </div>
    <div id="ladderLevelLabel" style="font-size:13px;font-weight:700">Ladder Score</div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Portfolio Spread</div>
  </div>

  <!-- Summary stats -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px" id="ladderStatStrip">
    <div class="stat-card"><div class="stat-label">Active FDs</div><div class="stat-value" id="ldrCount">—</div></div>
    <div class="stat-card"><div class="stat-label">Total Principal</div><div class="stat-value" id="ldrPrincipal">—</div></div>
    <div class="stat-card"><div class="stat-label">Total Maturity</div><div class="stat-value text-success" id="ldrMaturity">—</div></div>
    <div class="stat-card"><div class="stat-label">Total Interest</div><div class="stat-value text-success" id="ldrInterest">—</div></div>
    <div class="stat-card"><div class="stat-label">Avg Rate</div><div class="stat-value" id="ldrAvgRate">—</div></div>
  </div>
</div>

<!-- Gap Alerts -->
<div id="ladderGapAlerts" style="margin-bottom:16px"></div>

<!-- ── Gantt Timeline ── -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
    <h3 class="card-title">📅 FD Timeline — Gantt View</h3>
    <span style="font-size:12px;color:var(--text-muted)">Each bar = one FD (hover for details)</span>
  </div>
  <div class="card-body">
    <!-- Date axis labels -->
    <div id="ladderTimeAxis" style="position:relative;height:24px;margin-bottom:4px;font-size:11px;color:var(--text-muted)"></div>
    <!-- FD bars -->
    <div id="ladderGanttBody" style="display:flex;flex-direction:column;gap:8px">
      <div style="text-align:center;padding:32px;color:var(--text-muted)"><span class="spinner"></span> Loading...</div>
    </div>
    <!-- Today marker legend -->
    <div style="display:flex;align-items:center;gap:6px;margin-top:12px;font-size:11px;color:var(--text-muted)">
      <div style="width:2px;height:14px;background:#ef4444;border-radius:1px"></div> Today
      <div style="width:14px;height:3px;background:var(--primary);border-radius:1px;margin-left:12px"></div> Active FD tenure
      <div style="width:8px;height:8px;background:#16a34a;border-radius:50%;margin-left:12px"></div> Maturity point
    </div>
  </div>
</div>

<!-- ── Maturity Schedule ── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">

  <!-- Yearly bar chart -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">📊 Year-wise Maturity</h3></div>
    <div class="card-body" style="height:220px;position:relative">
      <canvas id="ladderYearChart"></canvas>
    </div>
  </div>

  <!-- Monthly timeline -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">🗓️ Monthly Schedule (Next 24M)</h3></div>
    <div class="card-body" style="max-height:220px;overflow-y:auto" id="ladderMonthList">
      <div style="text-align:center;color:var(--text-muted);padding:20px">Loading...</div>
    </div>
  </div>

</div>

<!-- ── Ladder Analysis Table ── -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header"><h3 class="card-title">🪜 Ladder Rungs — Sorted by Maturity</h3></div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>#</th><th>Bank</th><th>Principal</th><th>Rate %</th>
          <th>Start Date</th><th>Maturity Date</th><th>Tenure</th>
          <th class="text-right">Maturity Amount</th><th>Days Left</th><th>Status</th>
        </tr>
      </thead>
      <tbody id="ladderTable">
        <tr><td colspan="10" class="text-center text-muted">Loading...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Upcoming Reinvestment Plan ── -->
<div class="card" id="ladderReinvestCard" style="display:none;margin-bottom:24px">
  <div class="card-header">
    <h3 class="card-title">♻️ Reinvestment Plan — Next 12 Months</h3>
    <p style="margin:4px 0 0;font-size:13px;color:var(--text-muted)">FDs maturing soon — plan your reinvestment</p>
  </div>
  <div id="ladderReinvestBody" class="card-body" style="display:flex;flex-direction:column;gap:10px"></div>
</div>

<!-- ── Strategy Tips ── -->
<div class="card" style="margin-bottom:24px;background:linear-gradient(135deg,rgba(79,70,229,.05),rgba(16,185,129,.05));border:1.5px solid rgba(79,70,229,.15)">
  <div class="card-header"><h3 class="card-title">💡 FD Laddering Strategy Tips</h3></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px">
        <div style="font-size:13px;font-weight:700;margin-bottom:6px">🪜 What is FD Laddering?</div>
        <div style="font-size:12px;color:var(--text-muted);line-height:1.6">Split your FD corpus across multiple tenures (e.g., 1Y, 2Y, 3Y, 5Y). As each rung matures, reinvest at the longest tenure — giving you liquidity AND compounding.</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px">
        <div style="font-size:13px;font-weight:700;margin-bottom:6px">📐 Ideal Ladder Structure</div>
        <div style="font-size:12px;color:var(--text-muted);line-height:1.6">5 equal-sized FDs maturing every 12 months. Each year one matures — reinvest for 5Y. In 5Y, all FDs are at the highest (5Y) rate continuously.</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px">
        <div style="font-size:13px;font-weight:700;margin-bottom:6px">🏦 DICGC Safety Rule</div>
        <div style="font-size:12px;color:var(--text-muted);line-height:1.6">Keep ≤₹5L per bank. Spread across 3+ banks. Small Finance Banks offer higher rates (8–9.5%) and are equally DICGC-insured up to ₹5L.</div>
      </div>
    </div>
  </div>
</div>

</div><!-- /fdPanelLadder -->

<script>
/* ── t44: FD Laddering Visualization ──────────────────────────── */
const FDLadder = (() => {
  let _data    = null;
  let _yearChart = null;
  let _loaded  = false;
  const INR    = v => '₹' + Number(v||0).toLocaleString('en-IN', {maximumFractionDigits:0});
  const portId = () => window.WD?.selectedPortfolio || '';

  async function init() {
    if (_loaded) return;
    _loaded = true;
    await load();
  }

  async function load() {
    try {
      const res = await window.apiGet({ action: 'fd_ladder', portfolio_id: portId() });
      if (!res.success) { showError(res.message || 'Failed to load'); return; }
      _data = res.data;
      render();
    } catch(e) {
      showError('Network error: ' + e.message);
    }
  }

  function render() {
    if (!_data) return;
    renderScore();
    renderStats();
    renderGapAlerts();
    renderGantt();
    renderYearChart();
    renderMonthList();
    renderTable();
    renderReinvestPlan();
  }

  function renderScore() {
    const score = _data.ladder_score || 0;
    const level = _data.ladder_level || { label: 'N/A', color: '#6b7280' };
    const circumference = 251.2;
    const offset = circumference - (score / 100 * circumference);

    const arc = document.getElementById('ladderScoreArc');
    const num = document.getElementById('ladderScoreNum');
    const lbl = document.getElementById('ladderLevelLabel');
    if (arc) { arc.style.strokeDashoffset = offset; arc.style.stroke = level.color; }
    if (num) { num.textContent = score; num.style.color = level.color; }
    if (lbl) { lbl.textContent = level.label; lbl.style.color = level.color; }
  }

  function renderStats() {
    const t = _data.totals || {};
    const set = (id, val) => { const el = document.getElementById(id); if(el) el.textContent = val; };
    set('ldrCount',    t.count || 0);
    set('ldrPrincipal', INR(t.principal));
    set('ldrMaturity',  INR(t.maturity));
    set('ldrInterest',  INR(t.interest));
    set('ldrAvgRate',   (t.avg_rate || 0) + '% p.a.');
  }

  function renderGapAlerts() {
    const el = document.getElementById('ladderGapAlerts');
    if (!el) return;
    const gaps = _data.gaps || [];
    if (!gaps.length) { el.innerHTML = ''; return; }
    el.innerHTML = gaps.map(g => `
      <div style="background:#fef3c7;border:1.5px solid #fbbf24;border-radius:10px;padding:10px 16px;display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <span style="font-size:18px">⚠️</span>
        <div>
          <strong style="color:#92400e;font-size:13px">Liquidity Gap Detected — ${g.gap_label}</strong>
          <div style="font-size:12px;color:#78350f">No FD maturing from <strong>${g.from}</strong> to <strong>${g.to}</strong>. Consider adding a short-term FD in this window.</div>
        </div>
      </div>`).join('');
  }

  function renderGantt() {
    const fds   = _data.fds || [];
    const gantt = document.getElementById('ladderGanttBody');
    const axis  = document.getElementById('ladderTimeAxis');
    if (!gantt) return;

    if (!fds.length) {
      gantt.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted)">No active FDs to display</div>';
      return;
    }

    const totDays = _data.totals?.total_days || 365;
    const startStr = _data.totals?.timeline_start;
    const today = new Date();
    const start = startStr ? new Date(startStr) : today;
    const todayPct = Math.max(0, Math.min(100, (today - start) / (totDays * 86400000) * 100));

    // Axis labels (4 points)
    if (axis) {
      const labels = [];
      for (let i = 0; i <= 3; i++) {
        const d = new Date(start.getTime() + i * totDays / 3 * 86400000);
        labels.push({ pct: Math.round(i / 3 * 100), label: d.toLocaleDateString('en-IN', { month: 'short', year: '2-digit' }) });
      }
      axis.innerHTML = `<div style="position:relative;width:100%;height:24px">` +
        labels.map(l => `<span style="position:absolute;left:${l.pct}%;transform:translateX(-50%)">${l.label}</span>`).join('') +
        `<div style="position:absolute;left:${todayPct.toFixed(1)}%;top:0;width:2px;height:24px;background:#ef4444;border-radius:1px" title="Today"></div>` +
        `</div>`;
    }

    gantt.innerHTML = fds.map((fd, i) => {
      const left  = Math.max(0, fd.left_pct || 0);
      const width = Math.max(2, fd.width_pct || 2);
      const daysLeft = fd.days_left || 0;
      const badgeColor = daysLeft <= 0 ? '#6b7280' : daysLeft <= 30 ? '#dc2626' : daysLeft <= 90 ? '#d97706' : '#16a34a';
      const tooltip = `${fd.bank_name} | ${INR(fd.principal)} @ ${fd.rate}% | Matures: ${fd.maturity_date} (${daysLeft > 0 ? daysLeft + 'd left' : 'Matured'}) | ${INR(fd.maturity_amount)} at maturity`;

      return `<div style="position:relative;height:36px;background:var(--bg-secondary);border-radius:8px;overflow:visible" title="${tooltip}">
        <!-- Today line -->
        <div style="position:absolute;left:${todayPct.toFixed(1)}%;top:0;width:2px;height:100%;background:rgba(239,68,68,.5);z-index:5;pointer-events:none"></div>
        <!-- FD bar -->
        <div style="position:absolute;left:${left}%;width:${Math.min(width, 100-left)}%;height:100%;background:${fd.color};border-radius:6px;opacity:.85;cursor:pointer;transition:opacity .15s"
          onmouseover="this.style.opacity=1" onmouseout="this.style.opacity='.85'"
          title="${tooltip}">
          <div style="position:absolute;inset:0;display:flex;align-items:center;padding:0 8px;overflow:hidden">
            <span style="font-size:11px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
              ${fd.bank_name} · ${fd.rate}%
            </span>
          </div>
          <!-- Maturity dot -->
          <div style="position:absolute;right:-5px;top:50%;transform:translateY(-50%);width:10px;height:10px;border-radius:50%;background:#fff;border:2.5px solid ${badgeColor};z-index:2"></div>
        </div>
        <!-- Principal label left -->
        <div style="position:absolute;left:${Math.max(0, left - 0.5)}%;top:50%;transform:translate(-100%,-50%);padding-right:6px;font-size:10px;color:var(--text-muted);white-space:nowrap;pointer-events:none">
          ${INR(fd.principal)}
        </div>
      </div>`;
    }).join('');
  }

  function renderYearChart() {
    const canvas = document.getElementById('ladderYearChart');
    if (!canvas) return;
    const yearly = _data.yearly_summary || [];
    if (_yearChart) { _yearChart.destroy(); _yearChart = null; }
    if (!yearly.length) return;

    _yearChart = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: yearly.map(y => y.year),
        datasets: [
          { label: 'Principal',      data: yearly.map(y => y.principal), backgroundColor: 'rgba(79,70,229,.6)', borderRadius: 4 },
          { label: 'Maturity Value', data: yearly.map(y => y.maturity),  backgroundColor: 'rgba(16,185,129,.7)', borderRadius: 4 },
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { font: { size: 11 } } },
          tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${INR(ctx.raw)}` } }
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 11 } } },
          y: { ticks: { font: { size: 11 }, callback: v => v >= 100000 ? '₹'+(v/100000).toFixed(1)+'L' : '₹'+v.toLocaleString('en-IN') }, grid: { color: 'rgba(0,0,0,.05)' } }
        }
      }
    });
  }

  function renderMonthList() {
    const el = document.getElementById('ladderMonthList');
    if (!el) return;
    const monthly = _data.monthly_summary || [];
    if (!monthly.length) { el.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:20px">No FDs maturing in next 24 months</div>'; return; }

    el.innerHTML = monthly.map(m => `
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
        <div>
          <div style="font-size:13px;font-weight:700">${m.label}</div>
          <div style="font-size:11px;color:var(--text-muted)">${m.count} FD${m.count > 1 ? 's' : ''} · ${m.fds.join(', ')}</div>
        </div>
        <div style="text-align:right">
          <div style="font-size:13px;font-weight:800;color:var(--primary)">${INR(m.maturity)}</div>
          <div style="font-size:11px;color:var(--text-muted)">+${INR(m.interest)} interest</div>
        </div>
      </div>`).join('');
  }

  function renderTable() {
    const tbody = document.getElementById('ladderTable');
    if (!tbody) return;
    const fds = _data.fds || [];
    if (!fds.length) { tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No active FDs</td></tr>'; return; }

    tbody.innerHTML = fds.map((fd, i) => {
      const daysLeft = fd.days_left || 0;
      const badgeClass = daysLeft <= 0 ? 'badge-neutral' : daysLeft <= 30 ? 'badge-danger' : daysLeft <= 90 ? 'badge-warning' : 'badge-success';
      const tenureLabel = fd.tenure_months >= 12
        ? (Math.floor(fd.tenure_months / 12) + 'Y' + (fd.tenure_months % 12 > 0 ? ' ' + (fd.tenure_months % 12) + 'M' : ''))
        : fd.tenure_months + 'M';
      return `<tr>
        <td><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:${fd.color};vertical-align:middle;margin-right:4px"></span>${i+1}</td>
        <td><strong>${fd.bank_name}</strong></td>
        <td>${INR(fd.principal)}</td>
        <td>${fd.rate}%</td>
        <td>${fd.start_date}</td>
        <td>${fd.maturity_date}</td>
        <td>${tenureLabel}</td>
        <td class="text-right text-success"><strong>${INR(fd.maturity_amount)}</strong></td>
        <td><span class="badge ${badgeClass}">${daysLeft > 0 ? daysLeft + 'd' : 'Matured'}</span></td>
        <td><span class="badge badge-success">Active</span></td>
      </tr>`;
    }).join('');
  }

  function renderReinvestPlan() {
    const card = document.getElementById('ladderReinvestCard');
    const body = document.getElementById('ladderReinvestBody');
    if (!card || !body) return;
    const plan = _data.reinvest_plan || [];
    if (!plan.length) { card.style.display = 'none'; return; }

    card.style.display = '';
    body.innerHTML = plan.map(p => `
      <div style="display:flex;align-items:center;gap:14px;padding:12px 16px;background:var(--bg-secondary);border-radius:10px;border-left:4px solid ${p.color}">
        <div style="min-width:40px;height:40px;border-radius:50%;background:${p.color};display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px">🏦</div>
        <div style="flex:1">
          <div style="font-size:14px;font-weight:700">${p.bank}</div>
          <div style="font-size:12px;color:var(--text-muted)">Matures: ${p.maturity_date} &nbsp;·&nbsp; ${INR(p.maturity_amount)}</div>
          <div style="font-size:12px;color:var(--text-primary);margin-top:3px">💡 ${p.suggestion}</div>
        </div>
        <div style="text-align:right">
          <div style="font-size:14px;font-weight:800;color:var(--primary)">${INR(p.maturity_amount)}</div>
          <div style="font-size:11px;color:${p.days_left <= 30 ? '#dc2626' : 'var(--text-muted)'}">${p.days_left > 0 ? p.days_left + ' days left' : 'Matured'}</div>
        </div>
      </div>`).join('');
  }

  function showError(msg) {
    const gantt = document.getElementById('ladderGanttBody');
    if (gantt) gantt.innerHTML = `<div style="text-align:center;padding:40px;color:#dc2626">${msg}</div>`;
  }

  return { init, load };
})();
</script>

</script>

<script src="<?= APP_URL ?>/public/js/fd.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= APP_URL ?>/public/js/fd_rates.js?v=<?= ASSET_VERSION ?>"></script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';