<?php
/**
 * WealthDash — MF Transactions History Page
 * Updated: Summary stats bar, FY filter, Add Txn button, investment_fy column
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'MF Transactions';
$activePage  = 'mf_transactions';

ob_start();
?>

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
  <div>
    <h1 class="page-title">MF Transactions</h1>
    <p class="page-subtitle">Full transaction history — all portfolios</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= APP_URL ?>/templates/pages/mf_holdings.php" class="btn btn-ghost btn-sm">← Holdings</a>
    <button class="btn btn-primary" id="btnAddTxn">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"
           viewBox="0 0 24 24" style="margin-right:5px;vertical-align:-2px;">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      Add Transaction
    </button>
  </div>
</div>

<!-- ═══ SUMMARY STATS BAR ═══ -->
<div class="stats-grid" style="margin-bottom:18px;">
  <div class="stat-card">
    <div class="stat-label">Total Transactions</div>
    <div class="stat-value" id="statTotalTxns">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Invested (BUY)</div>
    <div class="stat-value" id="statTotalBuy">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Redeemed (SELL)</div>
    <div class="stat-value" id="statTotalSell">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Net Invested</div>
    <div class="stat-value" id="statNetInvested">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Unique Funds</div>
    <div class="stat-value" id="statUniqueFunds">—</div>
  </div>
</div>

<!-- ═══ FILTERS ROW ═══ -->
<div class="card" style="margin-bottom:16px;overflow:visible;">
  <div class="card-body" style="padding:12px 16px;overflow:visible;">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">

      <select id="txnFilterType" class="filter-select" data-custom-dropdown>
        <option value="">All Types</option>
        <option value="BUY">BUY</option>
        <option value="SELL">SELL</option>
        <option value="DIV_REINVEST">Div Reinvest</option>
        <option value="SWITCH_IN">Switch In</option>
        <option value="SWITCH_OUT">Switch Out</option>
      </select>

      <!-- FY dropdown — JS dynamically populate karta hai API response se -->
      <select id="txnFilterFy" class="filter-select" data-custom-dropdown>
        <option value="">All FYs</option>
      </select>

      <input type="date" id="txnFilterFrom" class="filter-select" title="From date" style="cursor:pointer;">
      <input type="date" id="txnFilterTo"   class="filter-select" title="To date"   style="cursor:pointer;">

      <input type="search" id="txnSearch" class="form-control"
             placeholder="Search fund…"
             style="flex:1;min-width:160px;max-width:260px;">

      <button class="btn btn-ghost btn-sm" id="btnTxnFilter">Apply</button>
      <button class="btn btn-ghost btn-sm" id="btnTxnReset">Reset</button>

      <div style="flex:1;"></div>

      <button class="btn btn-outline btn-sm" id="btnExportTxnCsv" title="Download CSV"
              style="display:flex;align-items:center;gap:6px;">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
          <polyline points="7 10 12 15 17 10"/>
          <line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
        Export CSV
      </button>

    </div>
  </div>
</div>

<!-- ═══ TRANSACTIONS TABLE ═══ -->
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
          <th>FY</th>
          <th>Platform</th>
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

  <div class="card-footer"
       style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:8px;">
      <label style="font-size:12px;color:var(--text-muted);">Show</label>
      <select id="txnPerPageSelect" class="form-select"
              style="width:75px;padding:4px 8px;font-size:12px;"
              onchange="changeTxnPerPage(this.value)">
        <option value="10" selected>10</option>
        <option value="25">25</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
      <span style="font-size:12px;color:var(--text-muted);">per page</span>
    </div>
    <div id="txnPaginationInfo" style="font-size:13px;color:var(--text-muted);"></div>
    <div id="txnPagination" style="display:flex;gap:4px;"></div>
  </div>
</div>

<!-- ═══ ADD / EDIT TRANSACTION MODAL ═══ -->
<div class="modal-overlay" id="modalAddTxn" style="display:none;">
  <div class="modal" style="max-width:600px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title" id="modalTxnTitle">Add MF Transaction</h3>
      <button class="modal-close btn btn-ghost btn-sm" id="btnCloseTxnModal">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="txnEditId">
      <input type="hidden" id="txnCsrf" value="<?= generate_csrf() ?>">

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Transaction Type *</label>
          <select id="txnType" class="form-control">
            <option value="BUY">BUY — Purchase</option>
            <option value="SELL">SELL — Redemption</option>
            <option value="DIV_REINVEST">Dividend Reinvestment</option>
            <option value="SWITCH_IN">Switch In</option>
            <option value="SWITCH_OUT">Switch Out</option>
          </select>
        </div>
      </div>

      <div class="form-group" style="position:relative;">
        <label class="form-label">Fund Name *</label>
        <input type="text" id="txnFundSearch" class="form-control"
               placeholder="Type fund name or scheme code…" autocomplete="off">
        <div id="fundSearchDropdown"
             style="display:none;position:absolute;left:0;right:0;top:100%;
                    background:var(--bg-card);border:1px solid var(--border-color);
                    border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);
                    max-height:280px;overflow-y:auto;z-index:2000;"></div>
        <input type="hidden" id="txnFundId">
        <div id="txnFundInfo" style="margin-top:4px;font-size:12px;color:var(--text-muted);min-height:18px;"></div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Date *</label>
          <input type="date" id="txnDate" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Folio Number</label>
          <input type="text" id="txnFolio" class="form-control" placeholder="Optional">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Units *</label>
          <input type="number" id="txnUnits" class="form-control"
                 placeholder="0.000" step="0.001" min="0.001">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">NAV (₹) *</label>
          <input type="number" id="txnNav" class="form-control"
                 placeholder="0.0000" step="0.0001" min="0.0001">
        </div>
        <div class="form-group" style="flex:0 0 130px;">
          <label class="form-label">Stamp Duty (₹)</label>
          <input type="number" id="txnStampDuty" class="form-control"
                 value="0" step="0.01" min="0">
        </div>
      </div>

      <div id="txnValuePreview"
           style="display:none;background:var(--bg-secondary);border-radius:8px;
                  padding:12px;margin:-8px 0 16px;font-size:13px;">
        <span style="color:var(--text-muted);">Total Amount: </span>
        <strong id="previewValue">₹0</strong>
        <span style="margin-left:16px;color:var(--text-muted);">Latest NAV: </span>
        <span id="previewCurrentNav">—</span>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Platform</label>
          <select id="txnPlatform" class="form-control">
            <option value="">— Select —</option>
            <option>Direct AMC</option><option>MF Central</option>
            <option>Groww</option><option>Zerodha Coin</option>
            <option>Kuvera</option><option>Paytm Money</option>
            <option>ET Money</option><option>Other</option>
          </select>
        </div>
        <div class="form-group" style="flex:2;">
          <label class="form-label">Notes</label>
          <input type="text" id="txnNotes" class="form-control" placeholder="Optional…">
        </div>
      </div>

      <div id="txnError"
           style="display:none;color:var(--danger);font-size:13px;
                  padding:8px 12px;background:rgba(239,68,68,.1);
                  border-radius:6px;margin-top:8px;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="btnCancelTxn">Cancel</button>
      <button class="btn btn-primary" id="btnSaveTxn">
        <span id="btnSaveTxnLabel">Save Transaction</span>
        <div class="spinner-sm" id="btnSaveTxnSpinner" style="display:none;"></div>
      </button>
    </div>
  </div>
</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = '
<script src="' . APP_URL . '/public/js/mf.js?v=' . filemtime(APP_ROOT.'/public/js/mf.js') . '"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  initCustomFilterDropdowns();

  // FY filter change — reload immediately
  document.getElementById("txnFilterFy")
    ?.addEventListener("change", function () { loadTransactions(1); });
});
</script>';

require_once APP_ROOT . '/templates/layout.php';
?>