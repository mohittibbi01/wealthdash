<?php
/**
 * WealthDash — Smallcase Portfolio Tracker Page
 * Task: t120
 * Path: templates/pages/smallcase.php
 */
defined('WEALTHDASH') or die();
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Smallcase';
$activePage  = 'smallcase';

ob_start();
?>

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
  <div>
    <h1 class="page-title">Smallcase Portfolios</h1>
    <p class="page-subtitle">Basket strategy tracker — thematic & factor-based investing</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost btn-sm" id="btnScCalcXirr">Calculate XIRR</button>
    <button class="btn btn-primary" id="btnAddSmallcase">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="margin-right:5px;vertical-align:-2px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Smallcase
    </button>
  </div>
</div>

<!-- ═══ SUMMARY ═══ -->
<div class="stats-grid" style="margin-bottom:18px;">
  <div class="stat-card">
    <div class="stat-label">Smallcases</div>
    <div class="stat-value" id="statScCount">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Invested</div>
    <div class="stat-value" id="statScInvested">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Current Value</div>
    <div class="stat-value" id="statScValue">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Gain / Loss</div>
    <div class="stat-value" id="statScGain">—</div>
  </div>
</div>

<!-- ═══ SMALLCASE CARDS ═══ -->
<div id="scCardsGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-bottom:24px;">
  <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--text-muted);">
    <div class="spinner"></div>
  </div>
</div>

<!-- ═══ HOLDINGS DETAIL PANEL ═══ -->
<div id="scDetailPanel" style="display:none;">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <h3 class="card-title" style="margin:0;" id="scDetailTitle">Holdings</h3>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-ghost btn-sm" id="btnScBulkImport">Bulk Import</button>
        <button class="btn btn-ghost btn-sm" id="btnScAddTxn">+ Transaction</button>
        <button class="btn btn-ghost btn-sm" id="btnScAddRebalance">+ Rebalance</button>
        <button class="btn btn-outline btn-sm" id="btnAddStockToSc">+ Add Stock</button>
        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('scDetailPanel').style.display='none'">✕ Close</button>
      </div>
    </div>

    <!-- Tabs -->
    <div style="padding:0 16px;border-bottom:1px solid var(--border-color);display:flex;gap:0;">
      <button class="sc-tab-btn active" data-tab="holdings" style="padding:10px 16px;background:none;border:none;border-bottom:2px solid var(--primary);color:var(--primary);font-weight:600;cursor:pointer;">Holdings</button>
      <button class="sc-tab-btn" data-tab="transactions" style="padding:10px 16px;background:none;border:none;border-bottom:2px solid transparent;color:var(--text-muted);cursor:pointer;">Transactions</button>
      <button class="sc-tab-btn" data-tab="rebalances" style="padding:10px 16px;background:none;border:none;border-bottom:2px solid transparent;color:var(--text-muted);cursor:pointer;">Rebalance History</button>
    </div>

    <!-- Holdings Tab -->
    <div id="tabHoldings" class="sc-tab-content">
      <div class="table-wrapper">
        <table class="table table-hover">
          <thead>
            <tr>
              <th>Symbol</th><th>Company</th><th class="text-right">Qty</th>
              <th class="text-right">Avg Price</th><th class="text-right">Invested</th>
              <th class="text-right">CMP</th><th class="text-right">Value</th>
              <th class="text-right">Weight</th><th style="width:80px;">Actions</th>
            </tr>
          </thead>
          <tbody id="scHoldingsBody"><tr><td colspan="9" class="text-center" style="padding:20px;color:var(--text-muted);">Select a smallcase above</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- Transactions Tab -->
    <div id="tabTransactions" class="sc-tab-content" style="display:none;">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr><th>Date</th><th>Type</th><th class="text-right">Amount</th><th>Notes</th><th style="width:60px;">Del</th></tr>
          </thead>
          <tbody id="scTxnBody"><tr><td colspan="5" class="text-center" style="padding:20px;">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- Rebalances Tab -->
    <div id="tabRebalances" class="sc-tab-content" style="display:none;">
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr><th>Date</th><th>Reason</th><th>Added</th><th>Removed</th><th>Portfolio Value</th></tr>
          </thead>
          <tbody id="scRebBody"><tr><td colspan="5" class="text-center" style="padding:20px;">Loading…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ═══ ADD SMALLCASE MODAL ═══ -->
<div class="modal-overlay" id="modalAddSc" style="display:none;">
  <div class="modal" style="max-width:540px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title" id="modalScTitle">Add Smallcase</h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="SC.closeModal('modalAddSc')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="scEditId">
      <div class="form-group">
        <label class="form-label">Smallcase Name *</label>
        <input type="text" id="scName" class="form-control" placeholder="e.g. All Weather Investing">
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Strategy Type</label>
          <input type="text" id="scStrategy" class="form-control" placeholder="e.g. Thematic, Factor">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Manager / Creator</label>
          <input type="text" id="scManager" class="form-control" placeholder="e.g. Windmill Capital">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Total Invested (₹)</label>
          <input type="number" id="scInvested" class="form-control" placeholder="0" step="1" min="0">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Subscription Fee (₹)</label>
          <input type="number" id="scSubFee" class="form-control" value="0" step="1" min="0">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Fee Frequency</label>
          <select id="scFeeFreq" class="form-control">
            <option value="">None</option>
            <option value="monthly">Monthly</option>
            <option value="quarterly">Quarterly</option>
            <option value="yearly">Yearly</option>
            <option value="one_time">One-Time</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea id="scDesc" class="form-control" rows="2" placeholder="What is this strategy about?"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" id="scNotes" class="form-control" placeholder="Optional…">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="SC.closeModal('modalAddSc')">Cancel</button>
      <button class="btn btn-primary" id="btnSaveSc">Save</button>
    </div>
  </div>
</div>

<!-- ═══ ADD STOCK MODAL ═══ -->
<div class="modal-overlay" id="modalAddStock" style="display:none;">
  <div class="modal" style="max-width:460px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title" id="modalStockTitle">Add Stock to Smallcase</h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="SC.closeModal('modalAddStock')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="stockHoldingEditId">
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Symbol *</label>
          <input type="text" id="stockSymbol" class="form-control" placeholder="e.g. RELIANCE" style="text-transform:uppercase;">
        </div>
        <div class="form-group" style="flex:2;">
          <label class="form-label">Company Name *</label>
          <input type="text" id="stockCompany" class="form-control" placeholder="Reliance Industries Ltd">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Qty *</label>
          <input type="number" id="stockQty" class="form-control" placeholder="0" step="0.0001" min="0.0001">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Avg Price *</label>
          <input type="number" id="stockAvgPrice" class="form-control" placeholder="0.00" step="0.01" min="0.01">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">CMP</label>
          <input type="number" id="stockCurPrice" class="form-control" placeholder="Optional" step="0.01">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Weight %</label>
          <input type="number" id="stockWeight" class="form-control" placeholder="0" step="0.01" min="0" max="100">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Target Weight %</label>
          <input type="number" id="stockTargetWeight" class="form-control" placeholder="0" step="0.01" min="0" max="100">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Sector</label>
          <input type="text" id="stockSector" class="form-control" placeholder="Optional">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="SC.closeModal('modalAddStock')">Cancel</button>
      <button class="btn btn-primary" id="btnSaveStock">Save Stock</button>
    </div>
  </div>
</div>

<!-- ═══ ADD TXN MODAL ═══ -->
<div class="modal-overlay" id="modalScTxn" style="display:none;">
  <div class="modal" style="max-width:420px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">Add Transaction</h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="SC.closeModal('modalScTxn')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Type *</label>
          <select id="scTxnType" class="form-control">
            <option value="invest">Invest</option>
            <option value="redeem">Redeem</option>
            <option value="rebalance">Rebalance</option>
            <option value="dividend">Dividend</option>
          </select>
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Date *</label>
          <input type="date" id="scTxnDate" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Amount (₹) *</label>
        <input type="number" id="scTxnAmount" class="form-control" placeholder="0" step="1" min="1">
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" id="scTxnNotes" class="form-control" placeholder="Optional">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="SC.closeModal('modalScTxn')">Cancel</button>
      <button class="btn btn-primary" id="btnSaveScTxn">Save</button>
    </div>
  </div>
</div>

<!-- ═══ BULK IMPORT MODAL ═══ -->
<div class="modal-overlay" id="modalBulkImport" style="display:none;">
  <div class="modal" style="max-width:600px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">Bulk Import Stocks</h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="SC.closeModal('modalBulkImport')">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:12px;">
        Paste CSV data: <code>symbol, company_name, quantity, avg_buy_price, sector</code><br>
        One stock per line. Header line optional.
      </p>
      <textarea id="bulkImportData" class="form-control" rows="10"
                style="font-family:monospace;font-size:12px;"
                placeholder="RELIANCE, Reliance Industries, 10, 2500, Energy
TCS, Tata Consultancy Services, 5, 3200, IT"></textarea>
      <div id="bulkParsePreview" style="margin-top:8px;font-size:12px;color:var(--text-muted);"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="SC.closeModal('modalBulkImport')">Cancel</button>
      <button class="btn btn-primary" id="btnRunBulkImport">Import</button>
    </div>
  </div>
</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = '
<script src="' . APP_URL . '/public/js/smallcase.js?v=' . time() . '"></script>';

require_once APP_ROOT . '/templates/layout.php';
