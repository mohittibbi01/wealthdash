<?php defined('WEALTHDASH') or die(); ?>
<section class="wd-page" id="page-stock-sip">
  <div class="page-header">
    <h1>Stock SIP</h1>
    <div class="page-actions">
      <button class="wd-btn wd-btn--secondary" id="sip-due-btn">Due Today</button>
      <button class="wd-btn wd-btn--primary" id="sip-add-btn">+ New SIP</button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="wd-cards wd-cards--4">
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Active SIPs</span>
      <span class="stat-value" id="sip-active-count">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Monthly Commitment</span>
      <span class="stat-value" id="sip-monthly-commit">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Total Invested</span>
      <span class="stat-value" id="sip-total-invested">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Due Today</span>
      <span class="stat-value wd-text--warn" id="sip-due-count">—</span>
    </div>
  </div>

  <!-- Due Today Banner -->
  <div id="sip-due-banner" style="display:none">
    <div class="wd-alert wd-alert--info" id="sip-due-inner"></div>
  </div>

  <!-- Tabs -->
  <div class="wd-tabs">
    <button class="wd-tab active" data-tab="active">Active SIPs</button>
    <button class="wd-tab" data-tab="paused">Paused / Stopped</button>
    <button class="wd-tab" data-tab="summary">Summary by Stock</button>
  </div>

  <!-- Active SIPs -->
  <div class="wd-tab-panel active" data-panel="active">
    <div class="wd-table-wrap">
      <table class="wd-table" id="sip-active-table">
        <thead>
          <tr>
            <th>Symbol</th>
            <th>Name</th>
            <th class="wd-num">SIP Amount (₹)</th>
            <th>Frequency</th>
            <th>SIP Day</th>
            <th>Start Date</th>
            <th>Next Due</th>
            <th class="wd-num">Installments Done</th>
            <th class="wd-num">Total Invested (₹)</th>
            <th class="wd-num">Avg Cost (₹)</th>
            <th class="wd-actions">Actions</th>
          </tr>
        </thead>
        <tbody id="sip-active-body">
          <tr><td colspan="11" class="wd-empty">No active SIPs.</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Paused/Stopped SIPs -->
  <div class="wd-tab-panel" data-panel="paused">
    <div class="wd-table-wrap">
      <table class="wd-table" id="sip-paused-table">
        <thead>
          <tr>
            <th>Symbol</th><th>Name</th>
            <th class="wd-num">SIP Amount (₹)</th>
            <th>Frequency</th><th>Start Date</th>
            <th>Stopped Date</th>
            <th class="wd-num">Total Invested (₹)</th>
            <th class="wd-actions">Actions</th>
          </tr>
        </thead>
        <tbody id="sip-paused-body">
          <tr><td colspan="8" class="wd-empty">No stopped SIPs.</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Summary by Stock -->
  <div class="wd-tab-panel" data-panel="summary">
    <div class="wd-table-wrap">
      <table class="wd-table" id="sip-summary-table">
        <thead>
          <tr>
            <th>Symbol</th><th>Name</th>
            <th>Frequency</th>
            <th class="wd-num">Per Installment (₹)</th>
            <th class="wd-num">Installments</th>
            <th class="wd-num">Total Invested (₹)</th>
            <th class="wd-num">Total Units</th>
            <th class="wd-num">Avg Cost (₹)</th>
            <th class="wd-num">Min Price (₹)</th>
            <th class="wd-num">Max Price (₹)</th>
          </tr>
        </thead>
        <tbody id="sip-summary-body">
          <tr><td colspan="10" class="wd-empty">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- Add / Edit SIP Modal -->
<div class="wd-modal" id="sip-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box">
    <div class="wd-modal__header">
      <h2 id="sip-modal-title">Create Stock SIP</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="sip-id">

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Symbol *</label>
          <input type="text" class="wd-input" id="sip-symbol" placeholder="TCS">
        </div>
        <div class="wd-field">
          <label>Stock Name *</label>
          <input type="text" class="wd-input" id="sip-name" placeholder="Tata Consultancy Services">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Exchange</label>
          <select class="wd-select" id="sip-exchange">
            <option value="NSE">NSE</option>
            <option value="BSE">BSE</option>
          </select>
        </div>
        <div class="wd-field">
          <label>Broker / Platform</label>
          <input type="text" class="wd-input" id="sip-broker" placeholder="Zerodha / Groww…">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>SIP Amount (₹) *</label>
          <input type="number" class="wd-input" id="sip-amount" step="1" min="1">
        </div>
        <div class="wd-field">
          <label>Frequency *</label>
          <select class="wd-select" id="sip-frequency">
            <option value="DAILY">Daily</option>
            <option value="WEEKLY">Weekly</option>
            <option value="FORTNIGHTLY">Fortnightly</option>
            <option value="MONTHLY" selected>Monthly</option>
            <option value="QUARTERLY">Quarterly</option>
          </select>
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label id="sip-day-label">SIP Day (of month)</label>
          <input type="number" class="wd-input" id="sip-day" min="1" max="28" value="1">
        </div>
        <div class="wd-field">
          <label>Start Date *</label>
          <input type="date" class="wd-input" id="sip-start">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>End Date <small>(leave blank = open-ended)</small></label>
          <input type="date" class="wd-input" id="sip-end">
        </div>
      </div>

      <div class="wd-field">
        <label>Notes</label>
        <textarea class="wd-input" id="sip-notes" rows="2"></textarea>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="sip-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="sip-save">Save SIP</button>
    </div>
  </div>
</div>

<!-- Record Installment Modal -->
<div class="wd-modal" id="sip-install-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box wd-modal__box--sm">
    <div class="wd-modal__header">
      <h2>Record Installment</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="sip-install-sip-id">
      <p class="wd-text--muted" id="sip-install-label"></p>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Date *</label>
          <input type="date" class="wd-input" id="sip-install-date">
        </div>
        <div class="wd-field">
          <label>Status</label>
          <select class="wd-select" id="sip-install-status">
            <option value="EXECUTED">Executed</option>
            <option value="SKIPPED">Skipped</option>
            <option value="FAILED">Failed</option>
          </select>
        </div>
      </div>
      <div class="wd-form-row" id="sip-install-price-row">
        <div class="wd-field">
          <label>Execution Price (₹) *</label>
          <input type="number" class="wd-input" id="sip-install-price" step="0.01" min="0">
        </div>
        <div class="wd-field">
          <label>Quantity</label>
          <input type="number" class="wd-input" id="sip-install-qty" step="0.000001" min="0" placeholder="Auto">
        </div>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="sip-install-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="sip-install-save">Record</button>
    </div>
  </div>
</div>
