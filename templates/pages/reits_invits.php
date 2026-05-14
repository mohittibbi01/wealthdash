<?php
/**
 * WealthDash — REITs & InvITs Holdings Page
 * Task: t115
 * Path: templates/pages/reits_invits.php
 */
defined('WEALTHDASH') or die();
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'REITs & InvITs';
$activePage  = 'reits_invits';

ob_start();
?>

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
  <div>
    <h1 class="page-title">REITs & InvITs</h1>
    <p class="page-subtitle">Real Estate & Infrastructure Investment Trusts</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost btn-sm" id="btnRefreshPrices">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:5px;vertical-align:-2px;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
      Refresh Prices
    </button>
    <button class="btn btn-primary" id="btnAddHolding">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="margin-right:5px;vertical-align:-2px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Holding
    </button>
  </div>
</div>

<!-- ═══ SUMMARY CARDS ═══ -->
<div class="stats-grid" style="margin-bottom:18px;">
  <div class="stat-card">
    <div class="stat-label">Total Holdings</div>
    <div class="stat-value" id="statHoldingsCount">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Invested</div>
    <div class="stat-value" id="statTotalInvested">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Current Value</div>
    <div class="stat-value" id="statCurrentValue">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Gain / Loss</div>
    <div class="stat-value" id="statGainLoss">—</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Distributions</div>
    <div class="stat-value" id="statDistributions">—</div>
  </div>
</div>

<!-- ═══ FILTER TABS ═══ -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:10px 16px;">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <button class="btn btn-sm tab-filter-btn active" data-type="">All</button>
      <button class="btn btn-sm tab-filter-btn" data-type="REIT">REITs</button>
      <button class="btn btn-sm tab-filter-btn" data-type="InvIT">InvITs</button>
      <div style="flex:1;"></div>
      <button class="btn btn-outline btn-sm" id="btnViewDistributions">Distribution History</button>
      <button class="btn btn-outline btn-sm" id="btnViewTransactions">Transactions</button>
    </div>
  </div>
</div>

<!-- ═══ HOLDINGS TABLE ═══ -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title" style="margin:0;">Holdings</h3>
  </div>
  <div class="table-wrapper">
    <table class="table table-hover" id="reitsTable">
      <thead>
        <tr>
          <th>Symbol</th>
          <th>Name</th>
          <th>Type</th>
          <th class="text-right">Units</th>
          <th class="text-right">Avg Price</th>
          <th class="text-right">Invested</th>
          <th class="text-right">CMP</th>
          <th class="text-right">Current Value</th>
          <th class="text-right">Gain/Loss</th>
          <th class="text-right">Dist. Income</th>
          <th style="width:100px;">Actions</th>
        </tr>
      </thead>
      <tbody id="reitsBody">
        <tr><td colspan="11" class="text-center" style="padding:40px;"><div class="spinner"></div></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ DISTRIBUTION PANEL (hidden by default) ═══ -->
<div id="distPanel" style="display:none;margin-top:20px;">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title" style="margin:0;">Distribution History</h3>
      <button class="btn btn-primary btn-sm" id="btnAddDist">+ Add Distribution</button>
    </div>
    <div class="table-wrapper">
      <table class="table" id="distTable">
        <thead>
          <tr>
            <th>Ex-Date</th><th>Pay Date</th><th>Symbol</th>
            <th>Type</th><th class="text-right">Per Unit</th>
            <th class="text-right">Units</th><th class="text-right">Gross</th>
            <th class="text-right">TDS</th><th class="text-right">Net</th>
            <th style="width:60px;">Del</th>
          </tr>
        </thead>
        <tbody id="distBody"><tr><td colspan="10" class="text-center">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══ ADD HOLDING MODAL ═══ -->
<div class="modal-overlay" id="modalAddHolding" style="display:none;">
  <div class="modal" style="max-width:560px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title" id="modalHoldingTitle">Add REIT / InvIT</h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="closeReitsModal('modalAddHolding')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="holdingEditId">

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Type *</label>
          <select id="holdingType" class="form-control">
            <option value="REIT">REIT</option>
            <option value="InvIT">InvIT</option>
          </select>
        </div>
        <div class="form-group" style="flex:2;position:relative;">
          <label class="form-label">Symbol / Trust *</label>
          <input type="text" id="holdingSymbolSearch" class="form-control" placeholder="Type symbol or name…" autocomplete="off">
          <div id="symbolDropdown" style="display:none;position:absolute;left:0;right:0;top:100%;background:var(--bg-card);border:1px solid var(--border-color);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);max-height:240px;overflow-y:auto;z-index:2000;"></div>
          <input type="hidden" id="holdingSymbol">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Full Name *</label>
        <input type="text" id="holdingName" class="form-control" placeholder="e.g. Embassy Office Parks REIT">
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Exchange</label>
          <select id="holdingExchange" class="form-control">
            <option value="NSE">NSE</option>
            <option value="BSE">BSE</option>
          </select>
        </div>
        <div class="form-group" style="flex:2;">
          <label class="form-label">ISIN</label>
          <input type="text" id="holdingIsin" class="form-control" placeholder="Optional">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Units *</label>
          <input type="number" id="holdingUnits" class="form-control" placeholder="0" step="0.0001" min="0.0001">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Avg Buy Price (₹) *</label>
          <input type="number" id="holdingAvgPrice" class="form-control" placeholder="0.00" step="0.01" min="0.01">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Current Price (₹)</label>
          <input type="number" id="holdingCurPrice" class="form-control" placeholder="Optional" step="0.01" min="0">
        </div>
      </div>

      <div id="holdingValuePreview" style="background:var(--bg-secondary);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:12px;display:none;">
        Total Invested: <strong id="previewInvested">₹0</strong>
      </div>

      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" id="holdingNotes" class="form-control" placeholder="Optional…">
      </div>

      <div id="holdingError" style="display:none;color:var(--danger);font-size:13px;padding:8px 12px;background:rgba(239,68,68,.1);border-radius:6px;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeReitsModal('modalAddHolding')">Cancel</button>
      <button class="btn btn-primary" id="btnSaveHolding">Save Holding</button>
    </div>
  </div>
</div>

<!-- ═══ ADD DISTRIBUTION MODAL ═══ -->
<div class="modal-overlay" id="modalAddDist" style="display:none;">
  <div class="modal" style="max-width:480px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">Add Distribution</h3>
      <button class="modal-close btn btn-ghost btn-sm" onclick="closeReitsModal('modalAddDist')">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Holding *</label>
        <select id="distHoldingId" class="form-control"><option value="">— Select —</option></select>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Type</label>
          <select id="distType" class="form-control">
            <option value="dividend">Dividend</option>
            <option value="interest">Interest</option>
            <option value="SPD">SPD</option>
            <option value="return_of_capital">Return of Capital</option>
          </select>
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Ex-Date *</label>
          <input type="date" id="distExDate" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Pay Date</label>
          <input type="date" id="distPayDate" class="form-control">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label class="form-label">Per Unit (₹) *</label>
          <input type="number" id="distPerUnit" class="form-control" placeholder="0.0000" step="0.0001" min="0">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">Units Held *</label>
          <input type="number" id="distUnitsHeld" class="form-control" placeholder="0" step="0.0001" min="0">
        </div>
        <div class="form-group" style="flex:1;">
          <label class="form-label">TDS (₹)</label>
          <input type="number" id="distTds" class="form-control" value="0" step="0.01" min="0">
        </div>
      </div>
      <div id="distPreview" style="background:var(--bg-secondary);border-radius:8px;padding:10px;font-size:13px;display:none;">
        Gross: <strong id="distGross">₹0</strong> &nbsp;|&nbsp; Net: <strong id="distNet">₹0</strong>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeReitsModal('modalAddDist')">Cancel</button>
      <button class="btn btn-primary" id="btnSaveDist">Save Distribution</button>
    </div>
  </div>
</div>

<?php
$pageContent = ob_get_clean();

$extraScripts = '
<script src="' . APP_URL . '/public/js/reits.js?v=' . time() . '"></script>';

require_once APP_ROOT . '/templates/layout.php';
