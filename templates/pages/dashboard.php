<?php
/**
 * WealthDash — Dashboard Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser = require_auth();
$pageTitle   = 'Dashboard';
$activePage  = 'dashboard';

// Get portfolios
$portfolios  = get_user_portfolios((int)$currentUser['id'], is_admin());
$portfolioId = get_user_portfolio_id((int)$currentUser['id']);
if (!$portfolioId && !empty($portfolios)) {
    $portfolioId = (int) $portfolios[0]['id'];
}

// ---- Dashboard data ----
$mfHoldings  = $portfolioId ? DB::fetchAll(
    'SELECT h.*, f.scheme_name, f.category, f.latest_nav, f.latest_nav_date, fh.short_name as fund_house
     FROM mf_holdings h
     JOIN funds f ON f.id = h.fund_id
     JOIN fund_houses fh ON fh.id = f.fund_house_id
     WHERE h.portfolio_id = ? AND h.is_active = 1
     ORDER BY h.value_now DESC
     LIMIT 5',
    [$portfolioId]
) : [];

$recentTxns = $portfolioId ? DB::fetchAll(
    'SELECT t.*, f.scheme_name FROM mf_transactions t
     JOIN funds f ON f.id = t.fund_id
     WHERE t.portfolio_id = ?
     ORDER BY t.txn_date DESC, t.id DESC LIMIT 8',
    [$portfolioId]
) : [];

$fdAccounts = $portfolioId ? DB::fetchAll(
    "SELECT * FROM fd_accounts WHERE portfolio_id = ? AND status = 'active'
     ORDER BY maturity_date ASC LIMIT 5",
    [$portfolioId]
) : [];

// FDs maturing in 30 days
$fdMaturingSoon = $portfolioId ? DB::fetchAll(
    "SELECT * FROM fd_accounts
     WHERE portfolio_id = ? AND status = 'active'
     AND maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY maturity_date ASC",
    [$portfolioId]
) : [];

// ---- Aggregate totals ----
$mfTotal  = $portfolioId ? (float) DB::fetchVal('SELECT COALESCE(SUM(value_now),0) FROM mf_holdings WHERE portfolio_id=? AND is_active=1', [$portfolioId]) : 0;
$mfCost   = $portfolioId ? (float) DB::fetchVal('SELECT COALESCE(SUM(total_invested),0) FROM mf_holdings WHERE portfolio_id=? AND is_active=1', [$portfolioId]) : 0;
$stTotal  = $portfolioId ? (float) DB::fetchVal('SELECT COALESCE(SUM(current_value),0) FROM stock_holdings WHERE portfolio_id=? AND is_active=1', [$portfolioId]) : 0;
$stCost   = $portfolioId ? (float) DB::fetchVal('SELECT COALESCE(SUM(total_invested),0) FROM stock_holdings WHERE portfolio_id=? AND is_active=1', [$portfolioId]) : 0;
$npsTotal = $portfolioId ? (float) DB::fetchVal('SELECT COALESCE(SUM(latest_value),0) FROM nps_holdings WHERE portfolio_id=?', [$portfolioId]) : 0;
$fdTotal  = $portfolioId ? (float) DB::fetchVal("SELECT COALESCE(SUM(principal),0) FROM fd_accounts WHERE portfolio_id=? AND status='active'", [$portfolioId]) : 0;
$savTotal = $portfolioId ? (float) DB::fetchVal('SELECT COALESCE(SUM(balance),0) FROM savings_accounts WHERE portfolio_id=? AND is_active=1', [$portfolioId]) : 0;

// t157: Emergency Fund — savings + liquid/overnight MF holdings
$liquidCats  = ['Debt — Liquid','Debt — Overnight','Debt — Ultra Short Duration','Debt — Money Market'];
$ph          = implode(',', array_fill(0, count($liquidCats), '?'));
$liquidTotal = $portfolioId ? (float) DB::fetchVal(
    "SELECT COALESCE(SUM(h.value_now),0)
     FROM mf_holdings h
     JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id = ? AND h.is_active = 1 AND f.category IN ($ph)",
    array_merge([$portfolioId], $liquidCats)
) : 0;
$emergencyFund = $savTotal + $liquidTotal; // savings accounts + liquid MF

$netWorth     = $mfTotal + $stTotal + $npsTotal + $fdTotal + $savTotal;
$totalInvested = $mfCost + $stCost + $npsTotal + $fdTotal + $savTotal;
$totalGain    = $netWorth - $totalInvested;
$totalGainPct = $totalInvested > 0 ? round($totalGain / $totalInvested * 100, 2) : 0;

// ---- NAV last updated ----
$navLastUpdated = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key = 'nav_last_updated'");

ob_start();
?>

<div class="section-gap">

  <?php if (!empty($fdMaturingSoon)): ?>
  <div class="alert alert-warning">
    ⚠️ <strong><?= count($fdMaturingSoon) ?> Fixed Deposit(s)</strong> maturing in the next 30 days.
    <a href="<?= APP_URL ?>/templates/pages/fd.php">View FDs →</a>
  </div>
  <?php endif; ?>

  <?php if (!$portfolioId): ?>
  <div class="card">
    <div class="card-body" style="text-align:center;padding:48px">
      <div style="font-size:48px;margin-bottom:16px">📊</div>
      <h2>Portfolio is empty</h2>
      <p style="color:var(--text-muted);margin:12px 0 24px">Start by adding mutual funds, stocks, or other assets.</p>
    </div>
  </div>
  <?php else: ?>

  <!-- NET WORTH STAT CARDS -->
  <div class="stat-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">

    <div class="stat-card">
      <div class="stat-label">Net Worth</div>
      <div class="stat-value" style="font-size:20px"><?= inr($netWorth) ?></div>
      <div class="stat-change <?= gain_class($totalGain) ?>">
        <?= $totalGain >= 0 ? '↑' : '↓' ?> <?= inr(abs($totalGain)) ?> (<?= pct(abs($totalGainPct)) ?>)
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-label">Total Invested</div>
      <div class="stat-value" style="font-size:20px"><?= inr($totalInvested) ?></div>
      <div class="stat-change text-muted">Cost basis</div>
    </div>

    <div class="stat-card">
      <div class="stat-label">Mutual Funds</div>
      <div class="stat-value" style="font-size:20px"><?= inr($mfTotal) ?></div>
      <div class="stat-change <?= gain_class($mfTotal - $mfCost) ?>">
        <?= pct($mfCost > 0 ? ($mfTotal - $mfCost) / $mfCost * 100 : 0) ?> gain
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-label">Stocks & ETF</div>
      <div class="stat-value" style="font-size:20px"><?= inr($stTotal) ?></div>
      <div class="stat-change <?= gain_class($stTotal - $stCost) ?>">
        <?= pct($stCost > 0 ? ($stTotal - $stCost) / $stCost * 100 : 0) ?> gain
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-label">NPS</div>
      <div class="stat-value" style="font-size:20px"><?= inr($npsTotal) ?></div>
      <div class="stat-change text-muted">Current value</div>
    </div>

    <div class="stat-card">
      <div class="stat-label">Fixed Deposits</div>
      <div class="stat-value" style="font-size:20px"><?= inr($fdTotal) ?></div>
      <div class="stat-change text-muted">Principal</div>
    </div>

    <div class="stat-card">
      <div class="stat-label">Savings</div>
      <div class="stat-value" style="font-size:20px"><?= inr($savTotal) ?></div>
      <div class="stat-change text-muted">Bank balance</div>
    </div>

  </div>

  <!-- t157: Emergency Fund Tracker -->
  <?php
  $efMonths        = 0;
  $efPct           = 0;
  $efStatus        = 'unknown';
  $efStatusColor   = 'var(--text-muted)';
  $efStatusIcon    = '❓';
  $efMsg           = '';
  $monthlyExpense  = 0;

  // Estimate monthly expense: total invested / tenure months (rough proxy)
  // Better: use savTotal as proxy for 1 month expense if nothing else available
  // Real monthly expense = ask user (stored in app_settings) OR estimate from transactions
  $storedExpense = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key='monthly_expense_estimate'");
  if ($storedExpense && (float)$storedExpense > 0) {
      $monthlyExpense = (float)$storedExpense;
  } elseif ($netWorth > 0) {
      // Rough fallback: assume 5% of net worth as annual expense → /12
      $monthlyExpense = round($netWorth * 0.05 / 12);
  }

  if ($monthlyExpense > 0) {
      $efMonths = $emergencyFund / $monthlyExpense;
      $efPct    = min(100, round($efMonths / 6 * 100));
      if ($efMonths >= 6)      { $efStatus = 'safe';    $efStatusColor = '#15803d'; $efStatusIcon = '🛡️'; $efMsg = 'Emergency fund adequate — 6+ months covered!'; }
      elseif ($efMonths >= 3)  { $efStatus = 'caution'; $efStatusColor = '#d97706'; $efStatusIcon = '⚠️'; $efMsg = 'Borderline — aim for 6 months. Add ₹' . number_format(max(0, 6*$monthlyExpense - $emergencyFund), 0) . ' more.'; }
      else                     { $efStatus = 'danger';  $efStatusColor = '#dc2626'; $efStatusIcon = '🚨'; $efMsg = 'Low! Add ₹' . number_format(max(0, 6*$monthlyExpense - $emergencyFund), 0) . ' to liquid/savings to reach 6 months.'; }
  }
  ?>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header">
      <span class="card-title">🛡️ Emergency Fund Tracker</span>
      <span style="font-size:11px;color:var(--text-muted);">Savings + Liquid MF</span>
    </div>
    <div class="card-body" style="padding:16px 20px;">
      <div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;">

        <!-- Left: numbers -->
        <div style="min-width:200px;flex:1;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
            <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
              <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Savings Balance</div>
              <div style="font-size:16px;font-weight:800;"><?= inr($savTotal) ?></div>
              <div style="font-size:10px;color:var(--text-muted);">Bank accounts</div>
            </div>
            <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
              <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Liquid Funds</div>
              <div style="font-size:16px;font-weight:800;"><?= inr($liquidTotal) ?></div>
              <div style="font-size:10px;color:var(--text-muted);">Liquid/Overnight MF</div>
            </div>
          </div>

          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <span style="font-size:12px;font-weight:700;color:var(--text-primary);">Total Emergency Fund</span>
            <span style="font-size:15px;font-weight:800;color:<?= $efStatusColor ?>;"><?= inr($emergencyFund) ?></span>
          </div>

          <?php if ($monthlyExpense > 0): ?>
          <!-- Progress bar: 0 to 6 months -->
          <div style="height:10px;background:var(--border);border-radius:99px;overflow:hidden;margin-bottom:6px;">
            <div style="height:100%;width:<?= $efPct ?>%;background:<?= $efStatus==='safe'?'linear-gradient(90deg,#22c55e,#86efac)':($efStatus==='caution'?'linear-gradient(90deg,#f59e0b,#fcd34d)':'linear-gradient(90deg,#ef4444,#fca5a5)') ?>;border-radius:99px;transition:width .5s;"></div>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);margin-bottom:10px;">
            <span>0 months</span>
            <span style="font-weight:700;color:<?= $efStatusColor ?>;"><?= round($efMonths, 1) ?> months covered</span>
            <span>6 months</span>
          </div>
          <div style="padding:8px 12px;border-radius:7px;background:<?= $efStatus==='safe'?'rgba(22,163,74,.07)':($efStatus==='caution'?'rgba(217,119,6,.07)':'rgba(220,38,38,.07)') ?>;border:1px solid <?= $efStatus==='safe'?'rgba(22,163,74,.2)':($efStatus==='caution'?'rgba(217,119,6,.2)':'rgba(220,38,38,.2)') ?>;font-size:12px;font-weight:600;color:<?= $efStatusColor ?>;">
            <?= $efStatusIcon ?> <?= $efMsg ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Right: setup monthly expense -->
        <div style="min-width:220px;max-width:260px;">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;">Monthly Expense Estimate</div>
          <div style="display:flex;gap:6px;align-items:center;margin-bottom:8px;">
            <span style="font-size:13px;font-weight:700;color:var(--text-muted);">₹</span>
            <input type="number" id="efExpenseInput" placeholder="e.g. 40000"
              value="<?= $monthlyExpense > 0 ? (int)$monthlyExpense : '' ?>"
              style="flex:1;padding:7px 10px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);"
              oninput="efSaveExpense(this.value)">
          </div>
          <button onclick="efSaveExpense(document.getElementById('efExpenseInput').value,true)"
            style="width:100%;padding:7px;border-radius:7px;border:1.5px solid var(--accent);background:rgba(37,99,235,.07);color:var(--accent);font-size:12px;font-weight:700;cursor:pointer;">
            💾 Save & Recalculate
          </button>
          <div style="margin-top:10px;font-size:11px;color:var(--text-muted);line-height:1.5;">
            💡 <strong>6 months rule:</strong> Emergency fund should cover 6 months of living expenses. Keep in <strong>Liquid Funds</strong> or <strong>High-interest Savings</strong> — not equity.
          </div>
          <div style="margin-top:8px;font-size:11px;color:var(--text-muted);">
            Ideal: <strong><?= $monthlyExpense > 0 ? inr($monthlyExpense * 6) : '₹?' ?></strong>
            &nbsp;·&nbsp; Current: <strong style="color:<?= $efStatusColor ?>;"><?= inr($emergencyFund) ?></strong>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- ALLOCATION CHART + TOP MF HOLDINGS -->
  <div class="grid-2" style="grid-template-columns:1fr 1.6fr">

    <!-- Allocation Doughnut -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">Asset Allocation</span>
      </div>
      <div class="card-body" style="height:240px">
        <canvas id="allocationChart"></canvas>
      </div>
    </div>

    <!-- Top MF Holdings -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">Top MF Holdings</span>
        <a href="<?= APP_URL ?>/templates/pages/mf_holdings.php" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <?php if (empty($mfHoldings)): ?>
        <div class="card-body table-empty">
          <div class="table-empty-icon">📈</div>
          <div class="table-empty-text">No MF holdings yet.<br>
            <a href="<?= APP_URL ?>/templates/pages/mf_holdings.php">Add your first transaction →</a>
          </div>
        </div>
      <?php else: ?>
      <div class="table-wrapper">
        <table class="table">
          <thead><tr>
            <th>Fund</th>
            <th class="text-right">Value</th>
            <th class="text-right">Gain</th>
            <th class="text-right">CAGR</th>
          </tr></thead>
          <tbody>
            <?php foreach ($mfHoldings as $h): ?>
            <tr>
              <td>
                <div style="font-size:13px;font-weight:500;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <?= e($h['scheme_name']) ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted)"><?= e($h['category'] ?? '') ?></div>
              </td>
              <td class="text-right font-600"><?= inr($h['value_now']) ?></td>
              <td class="text-right <?= gain_class($h['gain_loss']) ?>">
                <?= pct($h['gain_pct']) ?>
              </td>
              <td class="text-right <?= gain_class($h['cagr'] ?? 0) ?>">
                <?= $h['cagr'] !== null ? pct($h['cagr']) : '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- RECENT TRANSACTIONS + FDs -->
  <div class="grid-2">

    <div class="card">
      <div class="card-header">
        <span class="card-title">Recent Transactions</span>
        <a href="<?= APP_URL ?>/templates/pages/mf_transactions.php" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <?php if (empty($recentTxns)): ?>
        <div class="card-body table-empty">
          <div>No transactions yet.</div>
        </div>
      <?php else: ?>
      <div class="table-wrapper">
        <table class="table">
          <thead><tr>
            <th>Date</th>
            <th>Fund</th>
            <th class="text-right">Type</th>
            <th class="text-right">Amount</th>
          </tr></thead>
          <tbody>
            <?php foreach ($recentTxns as $t): ?>
            <tr>
              <td style="font-size:12px;white-space:nowrap"><?= date_display($t['txn_date']) ?></td>
              <td style="font-size:12px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= e($t['scheme_name']) ?>
              </td>
              <td class="text-right">
                <span class="badge <?= in_array($t['transaction_type'], ['BUY','DIV_REINVEST','SWITCH_IN']) ? 'badge-green' : 'badge-red' ?>">
                  <?= e($t['transaction_type']) ?>
                </span>
              </td>
              <td class="text-right font-600" style="font-size:13px"><?= inr($t['value_at_cost']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- FD Summary -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">Fixed Deposits</span>
        <a href="<?= APP_URL ?>/templates/pages/fd.php" class="btn btn-ghost btn-sm">View All</a>
      </div>
      <?php if (empty($fdAccounts)): ?>
        <div class="card-body table-empty"><div>No active FDs.</div></div>
      <?php else: ?>
      <div class="table-wrapper">
        <table class="table">
          <thead><tr>
            <th>Bank</th>
            <th class="text-right">Principal</th>
            <th class="text-right">Rate</th>
            <th class="text-right">Matures</th>
          </tr></thead>
          <tbody>
            <?php foreach ($fdAccounts as $fd): ?>
            <?php
              $daysLeft = days_between(date('Y-m-d'), $fd['maturity_date']);
              $urgency  = $daysLeft <= 30 ? 'text-loss' : ($daysLeft <= 90 ? 'text-gain' : '');
            ?>
            <tr>
              <td style="font-size:13px"><?= e($fd['bank_name']) ?></td>
              <td class="text-right font-600" style="font-size:13px"><?= inr($fd['principal']) ?></td>
              <td class="text-right" style="font-size:13px"><?= pct($fd['interest_rate']) ?></td>
              <td class="text-right <?= $urgency ?>" style="font-size:12px">
                <?= date_display($fd['maturity_date']) ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <?php endif; // end if portfolioId ?>

</div>


<!-- t205: NSC Interest — Deemed Reinvestment (Section 80C) -->
<div class="card" style="margin-bottom:20px;margin-top:8px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <h3 class="card-title">📋 NSC Interest — Deemed Reinvestment Tracker</h3>
    <span style="font-size:11px;color:var(--text-muted);">Section 80C deduction</span>
  </div>
  <div class="card-body" style="padding:16px;">
    <div style="background:rgba(59,130,246,.06);border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:14px;">
      💡 <strong>NSC Interest Rule:</strong> From Year 2 to Year 5, the interest earned on NSC is <em>deemed to be reinvested</em> and qualifies as a fresh Section 80C deduction each year — even though you don't physically reinvest it.
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px;">
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">NSC Face Value (₹)</div>
        <input type="number" id="nscFaceValue" placeholder="100000" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcNscInterest()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Purchase Date</div>
        <input type="date" id="nscPurchaseDate" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" onchange="calcNscInterest()">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">NSC Rate (%/yr)</div>
        <input type="number" id="nscRate" value="7.7" step="0.1" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;" oninput="calcNscInterest()">
      </div>
    </div>
    <div id="nscResult" style="display:none;"></div>
    <div style="font-size:11px;color:var(--text-muted);margin-top:6px;">NSC tenure: <strong>5 years</strong>. Rate: <strong>7.7% p.a.</strong> (Q1 FY25-26). Compounded annually but paid at maturity.</div>
  </div>
</div>

<script src="<?= APP_URL ?>/public/js/charts.js"></script>
<script>
function initPageCharts() {
  const mfVal  = <?= $mfTotal ?>;
  const stVal  = <?= $stTotal ?>;
  const npsVal = <?= $npsTotal ?>;
  const fdVal  = <?= $fdTotal ?>;
  const savVal = <?= $savTotal ?>;

  if (mfVal + stVal + npsVal + fdVal + savVal > 0) {
    createDoughnutChart('allocationChart',
      ['Mutual Funds', 'Stocks', 'NPS', 'Fixed Deposits', 'Savings'],
      [mfVal, stVal, npsVal, fdVal, savVal]
    );
  }
}
document.addEventListener('DOMContentLoaded', initPageCharts);

// t157: Emergency Fund — save monthly expense via API
function efSaveExpense(val, reload) {
  const v = parseFloat(val);
  if (!v || v < 1000) { if(reload) showToast('₹1,000 se zyada amount enter karo','warning'); return; }
  apiPost({action:'save_setting', key:'monthly_expense_estimate', value: v})
    .then(r => {
      if (r && r.success) {
        if (reload) { showToast('Saved! Recalculating...','success'); setTimeout(()=>location.reload(),800); }
      } else { showToast('Save failed','error'); }
    }).catch(()=>showToast('Network error','error'));
}
</script>


<script>
// t205: NSC Interest Deemed Reinvestment Calculator
function calcNscInterest() {
  const face     = parseFloat(document.getElementById('nscFaceValue')?.value)    || 0;
  const dateStr  = document.getElementById('nscPurchaseDate')?.value || '';
  const rate     = parseFloat(document.getElementById('nscRate')?.value || 7.7)  / 100;
  const res      = document.getElementById('nscResult');
  if (!res || !face) { if(res) res.style.display='none'; return; }

  const fmtI = v => v >= 1e5 ? '₹'+(v/1e5).toFixed(2)+'L' : '₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});
  const purchaseDate = dateStr ? new Date(dateStr) : new Date();
  const maturityDate = new Date(purchaseDate); maturityDate.setFullYear(maturityDate.getFullYear()+5);

  // Year-wise interest (compounded annually)
  let balance = face;
  const years = [];
  let totalInterest = 0;
  let totalDeemReinvest = 0;

  for (let y = 1; y <= 5; y++) {
    const yearStart = y === 1 ? face : years[y-2].closingBalance;
    const interest  = yearStart * rate;
    const closing   = yearStart + interest;
    totalInterest  += interest;
    // Deemed reinvestment: Years 2-5 only (Year 5 is taxable, not deductible)
    const deemedDeduction = y >= 2 && y <= 5 ? interest : 0;
    totalDeemReinvest += deemedDeduction;
    years.push({ y, openingBalance: yearStart, interest, closingBalance: closing, deemedDeduction });
  }
  const maturityValue = face + totalInterest;

  // Current year highlighted
  const now = new Date(); const elapsed = now > purchaseDate ? (now - purchaseDate) / (365.25*86400000) : 0;
  const currentYear = Math.min(5, Math.max(1, Math.ceil(elapsed)));

  res.style.display = '';
  res.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px;">
      <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Face Value</div>
        <div style="font-size:16px;font-weight:800;">${fmtI(face)}</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Maturity Value</div>
        <div style="font-size:16px;font-weight:800;color:#16a34a;">${fmtI(maturityValue)}</div>
        <div style="font-size:10px;color:var(--text-muted);">${maturityDate.toLocaleDateString('en-IN',{year:'numeric',month:'short'})}</div>
      </div>
      <div style="background:rgba(99,102,241,.07);border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Total 80C (Deemed)</div>
        <div style="font-size:16px;font-weight:800;color:#6366f1;">${fmtI(totalDeemReinvest)}</div>
        <div style="font-size:10px;color:var(--text-muted);">Years 2–5</div>
      </div>
    </div>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="background:var(--bg-secondary);">
            <th style="padding:7px 10px;text-align:left;font-weight:700;color:var(--text-muted);">Year</th>
            <th style="padding:7px 10px;text-align:right;font-weight:700;color:var(--text-muted);">Opening</th>
            <th style="padding:7px 10px;text-align:right;font-weight:700;color:var(--text-muted);">Interest</th>
            <th style="padding:7px 10px;text-align:right;font-weight:700;color:var(--text-muted);">Closing</th>
            <th style="padding:7px 10px;text-align:right;font-weight:700;color:var(--text-muted);">80C Deduction</th>
          </tr>
        </thead>
        <tbody>
          ${years.map(r => `
            <tr style="background:${r.y===currentYear?'rgba(99,102,241,.07)':'transparent'};border-bottom:1px solid var(--border);">
              <td style="padding:7px 10px;font-weight:${r.y===currentYear?800:500};">
                Year ${r.y} ${r.y===currentYear?'← Current':''}
              </td>
              <td style="padding:7px 10px;text-align:right;">${fmtI(r.openingBalance)}</td>
              <td style="padding:7px 10px;text-align:right;color:#16a34a;font-weight:600;">${fmtI(r.interest)}</td>
              <td style="padding:7px 10px;text-align:right;font-weight:600;">${fmtI(r.closingBalance)}</td>
              <td style="padding:7px 10px;text-align:right;font-weight:700;color:${r.deemedDeduction>0?'#6366f1':'var(--text-muted)'};">
                ${r.deemedDeduction>0 ? '✅ '+fmtI(r.deemedDeduction) : r.y===1?'—':'Taxable'}
              </td>
            </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';

