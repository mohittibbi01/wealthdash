<?php defined('WEALTHDASH') or die(); ?>
<section class="wd-page" id="page-tax-report">
  <div class="page-header">
    <h1>Tax Report — LTCG &amp; STCG</h1>
    <div class="page-actions">
      <select id="tr-fy-select" class="wd-select"></select>
      <button class="wd-btn wd-btn--secondary" id="tr-import-btn">Import Transactions</button>
      <button class="wd-btn wd-btn--primary" id="tr-add-btn">+ Add Transaction</button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="wd-cards wd-cards--4" id="tr-summary-cards">
    <div class="wd-card wd-card--stat">
      <span class="stat-label">STCG</span>
      <span class="stat-value" id="tr-stcg-val">—</span>
      <span class="stat-sub" id="tr-stcg-tax">Tax: —</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">LTCG</span>
      <span class="stat-value" id="tr-ltcg-val">—</span>
      <span class="stat-sub" id="tr-ltcg-tax">Tax: —</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">LTCG Exempt (₹1.25L)</span>
      <span class="stat-value" id="tr-ltcg-exempt">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Est. Total Tax</span>
      <span class="stat-value wd-text--danger" id="tr-total-tax">—</span>
    </div>
  </div>

  <!-- Tabs -->
  <div class="wd-tabs" id="tr-tabs">
    <button class="wd-tab active" data-tab="stcg">STCG Gains</button>
    <button class="wd-tab" data-tab="ltcg">LTCG Gains</button>
    <button class="wd-tab" data-tab="unrealized">Unrealized</button>
    <button class="wd-tab" data-tab="transactions">All Transactions</button>
  </div>

  <!-- STCG Table -->
  <div class="wd-tab-panel active" data-panel="stcg">
    <div class="wd-table-wrap">
      <table class="wd-table" id="tr-stcg-table">
        <thead>
          <tr>
            <th>Symbol</th><th>Qty</th><th>Buy Price</th><th>Sell Price</th>
            <th>Buy Date</th><th>Sell Date</th><th>Days</th>
            <th class="wd-num">P&amp;L</th>
          </tr>
        </thead>
        <tbody id="tr-stcg-body"><tr><td colspan="8" class="wd-empty">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- LTCG Table -->
  <div class="wd-tab-panel" data-panel="ltcg">
    <div class="wd-table-wrap">
      <table class="wd-table" id="tr-ltcg-table">
        <thead>
          <tr>
            <th>Symbol</th><th>Qty</th><th>Buy Price</th><th>Grandfathered</th>
            <th>Sell Price</th><th>Buy Date</th><th>Sell Date</th><th>Days</th>
            <th class="wd-num">P&amp;L</th>
          </tr>
        </thead>
        <tbody id="tr-ltcg-body"><tr><td colspan="9" class="wd-empty">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- Unrealized -->
  <div class="wd-tab-panel" data-panel="unrealized">
    <div class="wd-table-wrap">
      <table class="wd-table" id="tr-unrealized-table">
        <thead>
          <tr>
            <th>Symbol</th><th>Total Qty</th><th>Avg Buy Price</th>
            <th>Buy Date</th><th>Days Held</th><th>Projected Category</th>
          </tr>
        </thead>
        <tbody id="tr-unrealized-body"><tr><td colspan="6" class="wd-empty">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- All Transactions -->
  <div class="wd-tab-panel" data-panel="transactions">
    <div class="wd-toolbar">
      <input type="text" class="wd-input" id="tr-search" placeholder="Search symbol…">
      <select class="wd-select" id="tr-type-filter">
        <option value="">All Types</option>
        <option value="BUY">BUY</option>
        <option value="SELL">SELL</option>
      </select>
    </div>
    <div class="wd-table-wrap">
      <table class="wd-table" id="tr-tx-table">
        <thead>
          <tr>
            <th>Date</th><th>Symbol</th><th>Name</th><th>Type</th>
            <th>Qty</th><th class="wd-num">Price</th><th class="wd-num">Value</th>
            <th>Exchange</th><th class="wd-actions">Actions</th>
          </tr>
        </thead>
        <tbody id="tr-tx-body"><tr><td colspan="9" class="wd-empty">Loading…</td></tr></tbody>
      </table>
    </div>
    <div class="wd-pagination" id="tr-pagination"></div>
  </div>
</section>

<!-- Add/Edit Transaction Modal -->
<div class="wd-modal" id="tr-tx-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box">
    <div class="wd-modal__header">
      <h2 id="tr-tx-modal-title">Add Transaction</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="tr-tx-id">
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Symbol *</label>
          <input type="text" class="wd-input" id="tr-tx-symbol" placeholder="e.g. TCS">
        </div>
        <div class="wd-field">
          <label>Stock Name *</label>
          <input type="text" class="wd-input" id="tr-tx-name" placeholder="Tata Consultancy Services">
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Type *</label>
          <select class="wd-select" id="tr-tx-type">
            <option value="BUY">BUY</option>
            <option value="SELL">SELL</option>
          </select>
        </div>
        <div class="wd-field">
          <label>Exchange</label>
          <select class="wd-select" id="tr-tx-exchange">
            <option value="NSE">NSE</option>
            <option value="BSE">BSE</option>
          </select>
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Quantity *</label>
          <input type="number" class="wd-input" id="tr-tx-qty" step="0.0001" min="0">
        </div>
        <div class="wd-field">
          <label>Price (₹) *</label>
          <input type="number" class="wd-input" id="tr-tx-price" step="0.01" min="0">
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Date *</label>
          <input type="date" class="wd-input" id="tr-tx-date">
        </div>
        <div class="wd-field">
          <label>Brokerage (₹)</label>
          <input type="number" class="wd-input" id="tr-tx-brok" step="0.01" min="0" value="0">
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>ISIN</label>
          <input type="text" class="wd-input" id="tr-tx-isin" placeholder="INE002A01018">
        </div>
        <div class="wd-field">
          <label>Grandfathered Price (₹) <small>FMV 31-Jan-2018</small></label>
          <input type="number" class="wd-input" id="tr-tx-gprice" step="0.01" min="0">
        </div>
      </div>
      <div class="wd-field">
        <label>Notes</label>
        <textarea class="wd-input" id="tr-tx-notes" rows="2"></textarea>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="tr-tx-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="tr-tx-save">Save Transaction</button>
    </div>
  </div>
</div>
