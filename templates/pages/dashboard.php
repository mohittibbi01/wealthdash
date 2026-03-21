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
</script>

<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';

