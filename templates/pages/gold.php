<?php defined('WEALTHDASH') or die(); ?>
<section class="wd-page" id="page-gold">
  <div class="page-header">
    <h1>Gold Tracker</h1>
    <div class="page-actions">
      <button class="wd-btn wd-btn--secondary" id="gold-refresh-btn">Refresh Prices</button>
      <button class="wd-btn wd-btn--primary" id="gold-add-btn">+ Add Holding</button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="wd-cards wd-cards--4" id="gold-summary-cards">
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Physical Gold</span>
      <span class="stat-value" id="gold-physical-grams">—</span>
      <span class="stat-sub">grams (24K equiv.)</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Digital Gold</span>
      <span class="stat-value" id="gold-digital-grams">—</span>
      <span class="stat-sub">grams</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">ETF / Fund Units</span>
      <span class="stat-value" id="gold-etf-units">—</span>
      <span class="stat-sub">units</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Total Invested</span>
      <span class="stat-value" id="gold-total-invested">—</span>
    </div>
  </div>

  <!-- Type Filter Tabs -->
  <div class="wd-tabs" id="gold-tabs">
    <button class="wd-tab active" data-tab="all">All</button>
    <button class="wd-tab" data-tab="PHYSICAL">Physical</button>
    <button class="wd-tab" data-tab="DIGITAL">Digital</button>
    <button class="wd-tab" data-tab="ETF">ETF</button>
    <button class="wd-tab" data-tab="FUND">Fund</button>
  </div>

  <!-- Holdings Table -->
  <div class="wd-tab-panel active" data-panel="all">
    <div class="wd-table-wrap">
      <table class="wd-table" id="gold-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Type</th>
            <th>Sub-Type</th>
            <th>Purity</th>
            <th class="wd-num">Qty / Grams</th>
            <th class="wd-num">Buy Price</th>
            <th class="wd-num">Invested</th>
            <th>Buy Date</th>
            <th class="wd-actions">Actions</th>
          </tr>
        </thead>
        <tbody id="gold-table-body">
          <tr><td colspan="9" class="wd-empty">Loading…</td></tr>
        </tbody>
        <tfoot id="gold-table-foot"></tfoot>
      </table>
    </div>
  </div>
</section>

<!-- Add/Edit Holding Modal -->
<div class="wd-modal" id="gold-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box">
    <div class="wd-modal__header">
      <h2 id="gold-modal-title">Add Gold Holding</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="gold-id">

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Type *</label>
          <select class="wd-select" id="gold-type">
            <option value="PHYSICAL">Physical</option>
            <option value="DIGITAL">Digital</option>
            <option value="ETF">ETF</option>
            <option value="FUND">Fund</option>
          </select>
        </div>
        <div class="wd-field" id="gold-subtype-wrap">
          <label>Sub-Type</label>
          <input type="text" class="wd-input" id="gold-subtype" placeholder="coins / bar / GOLDBEES…">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Name *</label>
          <input type="text" class="wd-input" id="gold-name" placeholder="e.g. Gold Coin — MMTC 10g">
        </div>
        <div class="wd-field" id="gold-purity-wrap">
          <label>Purity</label>
          <select class="wd-select" id="gold-purity">
            <option value="24K">24K</option>
            <option value="22K">22K</option>
            <option value="18K">18K</option>
            <option value="14K">14K</option>
          </select>
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label id="gold-qty-label">Quantity (grams) *</label>
          <input type="number" class="wd-input" id="gold-qty" step="0.001" min="0">
        </div>
        <div class="wd-field">
          <label id="gold-price-label">Buy Price (₹/gram) *</label>
          <input type="number" class="wd-input" id="gold-price" step="0.01" min="0">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Buy Date *</label>
          <input type="date" class="wd-input" id="gold-date">
        </div>
        <div class="wd-field" id="gold-making-wrap">
          <label>Making Charges (₹)</label>
          <input type="number" class="wd-input" id="gold-making" step="0.01" min="0" value="0">
        </div>
      </div>

      <div class="wd-form-row" id="gold-folio-wrap">
        <div class="wd-field">
          <label>Folio / DP ID</label>
          <input type="text" class="wd-input" id="gold-folio" placeholder="Folio number or DP ID">
        </div>
        <div class="wd-field">
          <label>Custodian / Platform</label>
          <input type="text" class="wd-input" id="gold-custodian" placeholder="MMTC / Augmont / Zerodha">
        </div>
      </div>

      <div class="wd-field">
        <label>Notes</label>
        <textarea class="wd-input" id="gold-notes" rows="2"></textarea>
      </div>

      <div class="wd-info-box" id="gold-preview">
        Total Invested: <strong id="gold-preview-val">—</strong>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="gold-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="gold-save">Save</button>
    </div>
  </div>
</div>

<!-- Transaction (Buy/Sell) Modal -->
<div class="wd-modal" id="gold-tx-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box wd-modal__box--sm">
    <div class="wd-modal__header">
      <h2>Record Transaction</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="gold-tx-holding-id">
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Type *</label>
          <select class="wd-select" id="gold-tx-type">
            <option value="BUY">BUY</option>
            <option value="SELL">SELL</option>
            <option value="SIP">SIP</option>
          </select>
        </div>
        <div class="wd-field">
          <label>Date *</label>
          <input type="date" class="wd-input" id="gold-tx-date">
        </div>
      </div>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Quantity *</label>
          <input type="number" class="wd-input" id="gold-tx-qty" step="0.001" min="0">
        </div>
        <div class="wd-field">
          <label>Price (₹) *</label>
          <input type="number" class="wd-input" id="gold-tx-price" step="0.01" min="0">
        </div>
      </div>
      <div class="wd-field">
        <label>Charges (₹)</label>
        <input type="number" class="wd-input" id="gold-tx-charges" step="0.01" min="0" value="0">
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="gold-tx-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="gold-tx-save">Save</button>
    </div>
  </div>
</div>
