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

// Auto-mature: mark any active scheme whose maturity_date has passed
DB::run(
    "UPDATE po_schemes po
     JOIN portfolios p ON p.id = po.portfolio_id
     SET po.status = 'matured', po.updated_at = NOW()
     WHERE p.user_id = ?
       AND po.status = 'active'
       AND po.maturity_date IS NOT NULL
       AND po.maturity_date < CURDATE()",
    [$currentUser['id']]
);


// Fetch user's single portfolio
$portfolio = DB::fetchOne(
    "SELECT id, name FROM portfolios WHERE user_id=? ORDER BY id ASC LIMIT 1",
    [$currentUser['id']]
);
$portfolioId = (int)($portfolio['id'] ?? 0);

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
        <?php
        $poSchemeLabels = [
            'savings_account' => 'Post Office Savings Account',
            'rd'   => 'Recurring Deposit (RD)',
            'td'   => 'Time Deposit (TD)',
            'mis'  => 'Monthly Income Scheme (MIS)',
            'scss' => 'Senior Citizen Savings Scheme (SCSS)',
            'ppf'  => 'Public Provident Fund (PPF)',
            'ssy'  => 'Sukanya Samriddhi Yojana (SSY)',
            'nsc'  => 'National Savings Certificate (NSC)',
            'kvp'  => 'Kisan Vikas Patra (KVP)',
        ];
        foreach ($maturing as $m):
            $schemeLabel = $poSchemeLabels[$m['scheme_type']] ?? strtoupper($m['scheme_type']);
            // For RD, show monthly deposit amount; for others show principal
            $displayPrincipal = ($m['scheme_type'] === 'rd' && !empty($m['deposit_amount']))
                ? $m['deposit_amount'] : $m['principal'];
        ?>
        <tr>
          <td><strong><?= e($schemeLabel) ?></strong></td>
          <td><?= e($m['holder_name']) ?></td>
          <td style="font-family:monospace;font-size:12px;color:var(--text-muted)">
            <?= $m['account_number'] ? '••••'.substr($m['account_number'],-4) : '—' ?>
          </td>
          <td class="text-right">
            <?= inr($displayPrincipal) ?>
            <?php if ($m['scheme_type'] === 'rd'): ?>
              <br><small style="color:var(--text-muted);font-size:10px">per month</small>
            <?php endif; ?>
          </td>
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
      <input type="hidden" id="poFilterPortfolio" value="<?= $portfolioId ?>">
    </div>
  </div>
  <div class="table-wrapper">
    <table class="table" id="poTable">
      <thead>
        <tr>
          <th>Scheme</th>
          <th>Holder</th>
          <th>Account No.</th>
          <th class="text-right">Principal</th>
          <th class="text-right">Rate %</th>
          <th>Open Date</th>
          <th>Maturity Date</th>
          <th class="text-right">Payout / Maturity</th>
          <th class="text-right">Interest</th>
          <th class="text-right">Days Left</th>
          <th>Status</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="poBody">
        <tr>
          <td colspan="12" class="text-center" style="padding:40px;color:var(--text-muted)">
            <span class="spinner"></span> Loading...
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <div class="card-footer" id="poPaginationWrap" style="display:none;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:8px;">
      <label style="font-size:12px;color:var(--text-muted);">Show</label>
      <select id="poPerPageSelect" class="form-select" style="width:75px;padding:4px 8px;font-size:12px;" onchange="PO.changePerPage(this.value)">
        <option value="10" selected>10</option>
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="9999">All</option>
      </select>
      <span style="font-size:12px;color:var(--text-muted);">per page</span>
    </div>
    <div id="poPaginationInfo" style="font-size:13px;color:var(--text-muted);"></div>
    <div id="poPagination" style="display:flex;gap:4px;"></div>
  </div>
</div>

<!-- ═══ ADD / EDIT MODAL ═══ -->
<div class="modal-overlay" id="modalAddPo" style="display:none">
  <div class="modal" style="max-width:820px;width:95%">
    <div class="modal-header">
      <h3 class="modal-title" id="poModalTitle">Add Post Office Scheme</h3>
      <button class="modal-close" id="closePo">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="poEditId">

      <!-- Scheme Type Selector -->
      <div class="form-group" id="schemeTypeGroup">
        <label class="form-label">Scheme Type *</label>
        <div id="poSchemeGrid" style="display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-top:4px">
          <!-- Populated by JS -->
        </div>
      </div>

      <div id="poFormFields" style="display:none">
        <!-- Selected scheme info banner -->
        <div id="poSchemeBanner" style="border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:13px"></div>

        <div class="form-row">
          <input type="hidden" id="poPortfolio" value="<?= $portfolioId ?>">
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

        <!-- Row 1: Principal Amount | Open Date -->
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
            <label class="form-label">Open Date *</label>
            <input type="date" class="form-input" id="poOpenDate" onchange="PO.calcPreview()">
          </div>
        </div>

        <!-- Row 2: Tenure (TD only) | Maturity Date -->
        <div class="form-row">
          <div class="form-group" id="poTdTenureGroup" style="display:none">
            <label class="form-label">Tenure *</label>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px" id="poTdTenureGrid">
              <button type="button" class="td-tenure-btn" data-years="1" data-rate="6.9" onclick="PO.selectTdTenure(1,6.9)">
                <span style="font-weight:700;font-size:13px">1 Year</span>
                <span style="font-size:11px;color:var(--text-muted)">6.9% p.a.</span>
              </button>
              <button type="button" class="td-tenure-btn" data-years="2" data-rate="7.0" onclick="PO.selectTdTenure(2,7.0)">
                <span style="font-weight:700;font-size:13px">2 Years</span>
                <span style="font-size:11px;color:var(--text-muted)">7.0% p.a.</span>
              </button>
              <button type="button" class="td-tenure-btn" data-years="3" data-rate="7.1" onclick="PO.selectTdTenure(3,7.1)">
                <span style="font-weight:700;font-size:13px">3 Years</span>
                <span style="font-size:11px;color:var(--text-muted)">7.1% p.a.</span>
              </button>
              <button type="button" class="td-tenure-btn" data-years="5" data-rate="7.5" onclick="PO.selectTdTenure(5,7.5)">
                <span style="font-weight:700;font-size:13px">5 Years</span>
                <span style="font-size:11px;color:var(--text-muted)">7.5% p.a. · 80C</span>
              </button>
            </div>
          </div>
          <div class="form-group" id="poRateGroup">
            <label class="form-label">Interest Rate (% p.a.) *</label>
            <input type="number" class="form-input" id="poRate" step="0.01" min="0.01" max="15"
                   placeholder="7.10" oninput="PO.calcPreview()">
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
            <div><span style="color:var(--text-muted)" id="prevMatLabel">Maturity Amount</span><br>
              <strong id="prevMat" style="color:#16a34a">—</strong></div>
            <div><span style="color:var(--text-muted)" id="prevIntLabel">Total Interest</span><br>
              <strong id="prevInt" style="color:#16a34a">—</strong></div>
          </div>
          <!-- t16: KVP tenure hint -->
          <div id="kvpTenureHint" style="display:none;margin-top:8px;font-size:11px;color:#15803d;font-weight:600;"></div>
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
.td-tenure-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 8px 6px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: transparent;
  cursor: pointer;
  transition: all .15s;
  gap: 2px;
  color: var(--text-primary);
}
.td-tenure-btn:hover { border-color: var(--accent); background: var(--bg-surface-2); }
.td-tenure-btn.selected { border-color: #0891b2; background: rgba(8,145,178,.07); }

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
  padding: 7px 6px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  cursor: pointer;
  transition: all .15s;
  font-size: 12px;
  text-align: center;
}
.po-scheme-card:hover { border-color: var(--accent); background: var(--bg-surface-2); }
.po-scheme-card.selected { border-color: var(--accent); background: rgba(37,99,235,.07); }
.po-scheme-card .sc-icon { font-size: 15px; margin-bottom: 3px; }
.po-scheme-card .sc-label { font-weight: 700; font-size: 10px; color: var(--text-primary); line-height: 1.2; }
.po-scheme-card .sc-rate { font-size: 10px; color: var(--text-muted); margin-top: 2px; }
</style>


<!-- t201: RD Monthly Installment Tracker -->
<div class="card" style="margin-top:20px;margin-bottom:20px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <h3 class="card-title">🔄 RD Monthly Installment Tracker</h3>
    <span style="font-size:11px;color:var(--text-muted);">Track paid/missed months</span>
  </div>
  <div class="card-body" style="padding:16px;">
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">Select an RD scheme below to track monthly installments. Click a month to toggle Paid/Missed.</p>
    <div id="rdTrackerSchemeList" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
      <div style="color:var(--text-muted);font-size:13px;">Loading RD schemes…</div>
    </div>
    <div id="rdTrackerGrid" style="display:none;">
      <div id="rdTrackerHeader" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
        <div id="rdTrackerTitle" style="font-size:14px;font-weight:700;"></div>
        <div id="rdTrackerStats" style="display:flex;gap:12px;font-size:12px;"></div>
      </div>
      <div id="rdMonthGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:6px;"></div>
    </div>
  </div>
</div>

<!-- t204: SSY Complete Tracker -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <h3 class="card-title">👧 SSY (Sukanya Samriddhi Yojana) Tracker</h3>
    <span style="font-size:11px;color:var(--text-muted);">Account details + maturity projection</span>
  </div>
  <div class="card-body" style="padding:16px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Girl's Date of Birth</div>
        <input type="date" id="ssyDob" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" onchange="calcSSY()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Account Opening Date</div>
        <input type="date" id="ssyOpen" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" onchange="calcSSY()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Yearly Deposit (₹)</div>
        <input type="number" id="ssyYearly" placeholder="150000" value="150000" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcSSY()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Current Balance (₹)</div>
        <input type="number" id="ssyBalance" placeholder="0" value="0" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcSSY()">
      </div>
    </div>
    <div id="ssyResult" style="display:none;"></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">
      Interest rate: <strong>8.2% p.a.</strong> (Q1 FY25-26, compounded yearly). Deposit for 15 years, matures at girl's age 21. Partial withdrawal at 18 for education. Max ₹1.5L/year (80C eligible).
    </div>
  </div>
</div>

<!-- t203: PPF Annual Deposit Tracker -->
<?php
// Fetch user's PPF accounts from po_schemes
$ppfAccounts = DB::fetchAll(
    "SELECT po.id, po.holder_name, po.account_number, po.principal,
            po.opening_date, po.interest_rate, po.maturity_date,
            po.deposit_amount, po.notes
     FROM po_schemes po
     JOIN portfolios p ON p.id = po.portfolio_id
     WHERE p.user_id = ? AND po.scheme_type = 'ppf' AND po.status = 'active'
     ORDER BY po.opening_date ASC",
    [$currentUser['id']]
);

$fyStart = date('Y') . '-04-01';
if (date('m') < 4) $fyStart = (date('Y')-1) . '-04-01';
$fyEnd   = date('Y-m-d', strtotime($fyStart . ' +1 year -1 day'));
$fyLabel = substr($fyStart, 0, 4) . '-' . substr((string)((int)substr($fyStart,0,4)+1), 2);
$PPF_LIMIT = 150000;
?>
<div class="card" style="margin-top:20px;margin-bottom:20px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
    <span class="card-title">🛡️ PPF Annual Deposit Tracker — FY <?= $fyLabel ?></span>
    <span style="font-size:11px;color:var(--text-muted);">Limit: ₹1,50,000/year · Min: ₹500/year · Section 80C</span>
  </div>
  <div class="card-body" style="padding:16px 20px;">

  <?php if (empty($ppfAccounts)): ?>
    <div style="text-align:center;padding:24px;color:var(--text-muted);">
      <div style="font-size:32px;margin-bottom:8px;">🛡️</div>
      <div style="font-size:13px;">No active PPF accounts found. Add a PPF account above to track deposits.</div>
    </div>
  <?php else: ?>
    <?php foreach ($ppfAccounts as $ppf):
      $openDate    = $ppf['opening_date'] ?? date('Y-m-d');
      $yearsOpen   = max(1, (int)ceil((time() - strtotime($openDate)) / (365.25*86400)));
      $lockYear    = date('Y', strtotime($openDate . ' +15 years'));
      $partialYear = date('Y', strtotime($openDate . ' +5 years'));
      $acNo        = $ppf['account_number'] ? '••••'.substr($ppf['account_number'],-4) : '—';

      // This FY deposit = sum of transactions tagged to this PPF (from notes field JSON or simple estimate)
      // Since PO schemes don't have transaction table, use deposit_amount as monthly proxy
      $monthlyDep  = (float)($ppf['deposit_amount'] ?? 0);
      // Estimate FY deposit: if monthly deposit set, annualise (months remaining in FY)
      $fyDeposited = 0;
      if ($monthlyDep > 0) {
          $monthsInFy = 12;
          $fyDeposited = min($monthlyDep * $monthsInFy, $PPF_LIMIT);
      }
      // Allow manual override via app_settings key
      $overrideKey = 'ppf_fy_deposit_' . $ppf['id'] . '_' . substr($fyStart,0,4);
      $override = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key=?", [$overrideKey]);
      if ($override !== false && $override !== null) $fyDeposited = (float)$override;

      $remaining   = max(0, $PPF_LIMIT - $fyDeposited);
      $pct         = min(100, round($fyDeposited / $PPF_LIMIT * 100));
      $barColor    = $pct >= 100 ? '#15803d' : ($pct >= 50 ? '#2563eb' : '#d97706');
      $deadline    = date('Y') . '-03-31'; // FY end
      $daysLeft    = max(0, (int)ceil((strtotime($deadline) - time()) / 86400));
      $interestRate = (float)($ppf['interest_rate'] ?? 7.1);

      // Interest estimate for current balance
      $balance     = (float)($ppf['principal'] ?? 0);
      $annualInt   = round($balance * $interestRate / 100);
    ?>
    <div style="border:1.5px solid var(--border);border-radius:10px;padding:16px;margin-bottom:16px;">
      <!-- Header row -->
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
        <div>
          <div style="font-size:14px;font-weight:700;"><?= e($ppf['holder_name'] ?: 'PPF Account') ?></div>
          <div style="font-size:11px;color:var(--text-muted);">A/c: <?= $acNo ?> · Opened: <?= fmt_date($openDate) ?> · Rate: <?= $interestRate ?>%</div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:12px;color:var(--text-muted);">Balance</div>
          <div style="font-size:18px;font-weight:800;color:var(--text-primary);"><?= inr($balance) ?></div>
        </div>
      </div>

      <!-- Deposit progress -->
      <div style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
          <span style="font-size:12px;font-weight:700;">FY <?= $fyLabel ?> Deposits</span>
          <span style="font-size:13px;font-weight:800;color:<?= $barColor ?>;"><?= inr($fyDeposited) ?> / ₹1,50,000</span>
        </div>
        <div style="height:12px;background:var(--border);border-radius:99px;overflow:hidden;margin-bottom:5px;">
          <div style="height:100%;width:<?= $pct ?>%;background:<?= $barColor ?>;border-radius:99px;transition:width .5s;"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);">
          <span><?= $pct ?>% deposited</span>
          <span style="color:<?= $remaining>0?'#d97706':'#15803d';?>;">
            <?= $remaining > 0 ? '₹'.number_format($remaining,0).' more deposit kar sakte ho' : '✅ Annual limit reached!' ?>
          </span>
        </div>
      </div>

      <!-- Manual entry for this FY's deposit -->
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
        <input type="number" id="ppfDep_<?= $ppf['id'] ?>" placeholder="This FY total deposit (₹)"
          value="<?= $fyDeposited > 0 ? (int)$fyDeposited : '' ?>"
          style="flex:1;padding:7px 10px;border:1.5px solid var(--border);border-radius:7px;font-size:12px;background:var(--bg-secondary);color:var(--text-primary);">
        <button onclick="savePpfDeposit(<?= $ppf['id'] ?>, '<?= substr($fyStart,0,4) ?>')"
          style="padding:7px 14px;border-radius:7px;border:1.5px solid var(--accent);background:rgba(37,99,235,.07);color:var(--accent);font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;">
          💾 Save
        </button>
      </div>

      <!-- Info grid -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;font-size:11px;">
        <div style="background:var(--bg-secondary);border-radius:7px;padding:8px 10px;">
          <div style="color:var(--text-muted);font-weight:600;margin-bottom:2px;">Est. Interest (FY)</div>
          <div style="font-weight:800;color:#15803d;">+<?= inr($annualInt) ?></div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:7px;padding:8px 10px;">
          <div style="color:var(--text-muted);font-weight:600;margin-bottom:2px;">Days to FY End</div>
          <div style="font-weight:800;color:<?= $daysLeft < 30 ? '#dc2626' : '#d97706' ?>;"><?= $daysLeft ?> days</div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:7px;padding:8px 10px;">
          <div style="color:var(--text-muted);font-weight:600;margin-bottom:2px;">Partial Withdrawal</div>
          <div style="font-weight:800;"><?= $yearsOpen >= 5 ? '✅ Eligible (from '.$partialYear.')' : '🔒 '.max(0,5-$yearsOpen).' yrs left' ?></div>
        </div>
        <div style="background:var(--bg-secondary);border-radius:7px;padding:8px 10px;">
          <div style="color:var(--text-muted);font-weight:600;margin-bottom:2px;">Lock-in Ends</div>
          <div style="font-weight:800;"><?= $lockYear ?> (15 yr maturity)</div>
        </div>
      </div>

      <?php if ($daysLeft < 30 && $remaining > 0): ?>
      <div style="margin-top:10px;padding:8px 12px;border-radius:7px;background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.2);font-size:12px;font-weight:600;color:#dc2626;">
        🚨 March deadline close hai! <?= inr($remaining) ?> aur deposit karo to maximize 80C benefit.
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div style="font-size:11px;color:var(--text-muted);padding:8px;border-radius:6px;background:var(--bg-secondary);">
      💡 <strong>PPF Rules:</strong> Min ₹500/year · Max ₹1,50,000/year · 15yr lock-in · Interest tax-free · Compounded annually (1 April pe) · Partial withdrawal from year 6 onwards (50% of balance 4yr ago).
    </div>
  <?php endif; ?>
  </div>
</div>

<script>
// t203: Save PPF FY deposit amount
function savePpfDeposit(ppfId, fy) {
  const val = parseFloat(document.getElementById('ppfDep_' + ppfId)?.value);
  if (!val || val < 0 || val > 150000) {
    showToast('Valid amount enter karo (₹0 – ₹1,50,000)', 'warning'); return;
  }
  const key = 'ppf_fy_deposit_' + ppfId + '_' + fy;
  apiPost({ action: 'save_setting', key, value: val })
    .then(r => {
      if (r && r.success) { showToast('PPF deposit saved ✅', 'success'); setTimeout(() => location.reload(), 700); }
      else showToast('Save failed', 'error');
    }).catch(() => showToast('Network error', 'error'));
}
</script>

<script src="<?= APP_URL ?>/public/js/post_office.js?v=<?= ASSET_VERSION ?>"></script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';