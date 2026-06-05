<?php defined('WEALTHDASH') or die(); ?>
<section class="wd-page" id="page-international">
  <div class="page-header">
    <h1>International Stocks</h1>
    <div class="page-actions">
      <button class="wd-btn wd-btn--secondary" id="intl-lrs-btn">LRS Tracker</button>
      <button class="wd-btn wd-btn--secondary" id="intl-refresh-btn">Refresh Prices</button>
      <button class="wd-btn wd-btn--primary" id="intl-add-btn">+ Add Holding</button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="wd-cards wd-cards--4">
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Total Invested (₹)</span>
      <span class="stat-value" id="intl-total-inr">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Current Value (₹)</span>
      <span class="stat-value" id="intl-current-inr">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Overall P&amp;L (₹)</span>
      <span class="stat-value" id="intl-pnl-inr">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">LRS Used (FY)</span>
      <span class="stat-value" id="intl-lrs-used">—</span>
      <span class="stat-sub" id="intl-lrs-pct">— of ₹2.5Cr</span>
    </div>
  </div>

  <!-- Tabs -->
  <div class="wd-tabs" id="intl-tabs">
    <button class="wd-tab active" data-tab="holdings">Holdings</button>
    <button class="wd-tab" data-tab="lrs">LRS Remittances</button>
  </div>

  <!-- Holdings Panel -->
  <div class="wd-tab-panel active" data-panel="holdings">
    <div class="wd-toolbar">
      <select class="wd-select" id="intl-exch-filter">
        <option value="">All Exchanges</option>
        <option value="NYSE">NYSE</option>
        <option value="NASDAQ">NASDAQ</option>
        <option value="LSE">LSE</option>
        <option value="OTHER">Other</option>
      </select>
      <input type="text" class="wd-input" id="intl-search" placeholder="Search ticker or name…">
    </div>
    <div class="wd-table-wrap">
      <table class="wd-table" id="intl-table">
        <thead>
          <tr>
            <th>Ticker</th>
            <th>Name</th>
            <th>Exchange</th>
            <th class="wd-num">Qty</th>
            <th class="wd-num">Avg Price</th>
            <th class="wd-num">Avg Price (₹)</th>
            <th class="wd-num">Current Price</th>
            <th class="wd-num">Invested (₹)</th>
            <th class="wd-num">Current (₹)</th>
            <th class="wd-num">P&amp;L (₹)</th>
            <th class="wd-num">P&amp;L %</th>
            <th>Platform</th>
            <th class="wd-actions">Actions</th>
          </tr>
        </thead>
        <tbody id="intl-table-body">
          <tr><td colspan="13" class="wd-empty">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- LRS Panel -->
  <div class="wd-tab-panel" data-panel="lrs">
    <div class="wd-toolbar">
      <select class="wd-select" id="intl-lrs-fy"></select>
      <button class="wd-btn wd-btn--primary wd-btn--sm" id="intl-lrs-add-btn">+ Add Remittance</button>
    </div>

    <!-- LRS Progress -->
    <div class="wd-card" id="intl-lrs-progress-card">
      <div class="lrs-progress-label">
        LRS Utilized: <strong id="intl-lrs-progress-val">—</strong> / ₹2.5 Cr
        <span id="intl-tcs-badge" class="wd-badge wd-badge--warn" style="display:none">TCS Applicable</span>
      </div>
      <div class="wd-progress">
        <div class="wd-progress__bar" id="intl-lrs-bar" style="width:0%"></div>
      </div>
    </div>

    <div class="wd-table-wrap">
      <table class="wd-table" id="intl-lrs-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Amount (₹)</th>
            <th>Amount (Foreign)</th>
            <th>Currency</th>
            <th class="wd-num">Exchange Rate</th>
            <th>Purpose</th>
            <th>Bank</th>
            <th class="wd-num">Forex Charges</th>
            <th class="wd-num">TCS Paid</th>
            <th class="wd-actions">Actions</th>
          </tr>
        </thead>
        <tbody id="intl-lrs-body">
          <tr><td colspan="10" class="wd-empty">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- Add Holding Modal -->
<div class="wd-modal" id="intl-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box wd-modal__box--lg">
    <div class="wd-modal__header">
      <h2 id="intl-modal-title">Add International Holding</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="intl-id">
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Ticker *</label>
          <input type="text" class="wd-input" id="intl-ticker" placeholder="AAPL">
        </div>
        <div class="wd-field">
          <label>Stock Name *</label>
          <input type="text" class="wd-input" id="intl-name" placeholder="Apple Inc.">
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Exchange *</label>
          <input type="text" class="wd-input" id="intl-exchange" placeholder="NASDAQ">
        </div>
        <div class="wd-field">
          <label>Currency *</label>
          <select class="wd-select" id="intl-currency">
            <option value="USD">USD</option>
            <option value="GBP">GBP</option>
            <option value="EUR">EUR</option>
            <option value="HKD">HKD</option>
            <option value="SGD">SGD</option>
          </select>
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Quantity *</label>
          <input type="number" class="wd-input" id="intl-qty" step="0.000001" min="0">
        </div>
        <div class="wd-field">
          <label>Avg Buy Price (Foreign) *</label>
          <input type="number" class="wd-input" id="intl-bp-foreign" step="0.0001" min="0">
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Avg Buy Price (₹) *</label>
          <input type="number" class="wd-input" id="intl-bp-inr" step="0.01" min="0">
        </div>
        <div class="wd-field">
          <label>Exchange Rate (at purchase)</label>
          <input type="number" class="wd-input" id="intl-rate" step="0.01" min="0" placeholder="Auto-calc">
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Platform / Broker</label>
          <input type="text" class="wd-input" id="intl-platform" placeholder="Vested / Indmoney / Stockal">
        </div>
        <div class="wd-field">
          <label>Sector</label>
          <input type="text" class="wd-input" id="intl-sector" placeholder="Technology">
        </div>
      </div>
      <div class="wd-field">
        <label>Notes</label>
        <textarea class="wd-input" id="intl-notes" rows="2"></textarea>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="intl-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="intl-save">Save</button>
    </div>
  </div>
</div>

<!-- Add LRS Modal -->
<div class="wd-modal" id="intl-lrs-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box">
    <div class="wd-modal__header">
      <h2>Add LRS Remittance</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Date *</label>
          <input type="date" class="wd-input" id="lrs-date">
        </div>
        <div class="wd-field">
          <label>Currency *</label>
          <select class="wd-select" id="lrs-currency">
            <option value="USD">USD</option>
            <option value="GBP">GBP</option>
            <option value="EUR">EUR</option>
          </select>
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Amount (₹) *</label>
          <input type="number" class="wd-input" id="lrs-inr" step="1" min="0">
        </div>
        <div class="wd-field">
          <label>Amount (Foreign) *</label>
          <input type="number" class="wd-input" id="lrs-foreign" step="0.01" min="0">
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Exchange Rate *</label>
          <input type="number" class="wd-input" id="lrs-rate" step="0.01" min="0">
        </div>
        <div class="wd-field">
          <label>Bank</label>
          <input type="text" class="wd-input" id="lrs-bank" placeholder="HDFC / ICICI…">
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Forex Charges (₹)</label>
          <input type="number" class="wd-input" id="lrs-forex" step="0.01" min="0" value="0">
        </div>
        <div class="wd-field">
          <label>TCS Paid (₹) <small>@20% above ₹7L</small></label>
          <input type="number" class="wd-input" id="lrs-tcs" step="0.01" min="0" value="0">
        </div>
      </div>
      <div class="wd-field">
        <label>Purpose</label>
        <input type="text" class="wd-input" id="lrs-purpose" value="Investment">
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="lrs-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="lrs-save">Save Remittance</button>
    </div>
  </div>
</div>
