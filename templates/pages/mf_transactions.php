<?php
/**
 * WealthDash — MF Transactions History Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'MF Transactions';
$activePage  = 'mf_transactions';

$db = DB::conn();
$pStmt = $db->prepare("SELECT id, name FROM portfolios WHERE user_id=? ORDER BY name");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">MF Transactions</h1>
    <p class="page-subtitle">Full transaction history</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/templates/pages/mf_holdings.php" class="btn btn-ghost">← Holdings View</a>
    <button class="btn btn-primary" id="btnAddTxn">+ Add Transaction</button>
  </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:14px 20px;">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
      <select id="txnFilterPortfolio" class="form-control" style="min-width:180px;">
        <option value="">All Portfolios</option>
        <?php foreach ($portfolios as $p): ?>
        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="txnFilterType" class="form-control">
        <option value="">All Types</option>
        <option value="BUY">BUY</option>
        <option value="SELL">SELL</option>
        <option value="DIV_REINVEST">Div Reinvest</option>
        <option value="SWITCH_IN">Switch In</option>
        <option value="SWITCH_OUT">Switch Out</option>
      </select>
      <input type="date" id="txnFilterFrom" class="form-control" style="width:150px;" title="From date">
      <input type="date" id="txnFilterTo" class="form-control" style="width:150px;" title="To date">
      <input type="search" id="txnSearch" class="form-control" placeholder="Search fund..." style="min-width:200px;">
      <button class="btn btn-ghost btn-sm" id="btnTxnFilter">Apply</button>
      <button class="btn btn-ghost btn-sm" id="btnTxnReset">Reset</button>
      <div style="flex:1;"></div>
      <button class="btn btn-ghost btn-sm" id="btnExportTxnCsv" title="Download CSV">↓ Export</button>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <h3 class="card-title" style="margin:0;">Transactions</h3>
    <span id="txnTotalCount" style="font-size:13px;color:var(--text-muted);"></span>
  </div>
  <div class="table-wrapper">
    <table class="table table-hover" id="txnTable">
      <thead>
        <tr>
          <th class="sortable" data-col="txn_date">Date</th>
          <th class="sortable" data-col="scheme_name">Fund</th>
          <th>Folio</th>
          <th class="sortable" data-col="transaction_type">Type</th>
          <th class="text-right sortable" data-col="units">Units</th>
          <th class="text-right sortable" data-col="nav">NAV</th>
          <th class="text-right sortable" data-col="value_at_cost">Amount</th>
          <th>Platform</th>
          <th>Portfolio</th>
          <th style="width:80px;">Actions</th>
        </tr>
      </thead>
      <tbody id="txnBody">
        <tr><td colspan="10" class="text-center" style="padding:40px;">
          <div class="spinner"></div>
        </td></tr>
      </tbody>
    </table>
  </div>
  <!-- Pagination -->
  <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;">
    <div id="txnPaginationInfo" style="font-size:13px;color:var(--text-muted);"></div>
    <div id="txnPagination" style="display:flex;gap:4px;"></div>
  </div>
</div>

<?php
$pageContent = ob_get_clean();
$extraScripts = '<script src="' . APP_URL . '/public/js/mf.js?v=2"></script>';
require_once APP_ROOT . '/templates/layout.php';
?>

