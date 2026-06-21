<?php defined('WEALTHDASH') or die(); ?>
<section class="wd-page" id="page-rbi">
  <div class="page-header">
    <h1>RBI / G-Sec / T-Bills</h1>
    <div class="page-actions">
      <button class="wd-btn wd-btn--secondary" id="rbi-upcoming-btn">Upcoming Cashflows</button>
      <button class="wd-btn wd-btn--primary" id="rbi-add-btn">+ Add Security</button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="wd-cards wd-cards--4">
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Total Invested</span>
      <span class="stat-value" id="rbi-total-invested">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Total Face Value</span>
      <span class="stat-value" id="rbi-total-fv">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Avg Coupon Rate</span>
      <span class="stat-value" id="rbi-avg-coupon">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Cashflows (180d)</span>
      <span class="stat-value" id="rbi-upcoming-sum">—</span>
    </div>
  </div>

  <!-- Type Tabs -->
  <div class="wd-tabs" id="rbi-tabs">
    <button class="wd-tab active" data-tab="all">All</button>
    <button class="wd-tab" data-tab="RBI_FRB">RBI FRB</button>
    <button class="wd-tab" data-tab="GSEC">G-Sec</button>
    <button class="wd-tab" data-tab="TBILL">T-Bill</button>
    <button class="wd-tab" data-tab="SDL">SDL</button>
  </div>

  <!-- Holdings Table -->
  <div class="wd-tab-panel active" data-panel="all">
    <div class="wd-table-wrap">
      <table class="wd-table" id="rbi-table">
        <thead>
          <tr>
            <th>Security</th>
            <th>Type</th>
            <th>ISIN</th>
            <th class="wd-num">Face Value</th>
            <th class="wd-num">Qty</th>
            <th class="wd-num">Purchase Price</th>
            <th class="wd-num">Coupon / Yield %</th>
            <th>Frequency</th>
            <th>Platform</th>
            <th>Purchase Date</th>
            <th>Maturity</th>
            <th>Days Left</th>
            <th class="wd-actions">Actions</th>
          </tr>
        </thead>
        <tbody id="rbi-table-body">
          <tr><td colspan="13" class="wd-empty">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Cashflow Timeline Panel -->
  <div class="wd-panel" id="rbi-cf-panel" style="display:none">
    <div class="wd-panel__header">
      <h3 id="rbi-cf-title">Cashflow Schedule</h3>
      <button class="wd-btn wd-btn--ghost wd-btn--sm" id="rbi-cf-close">✕ Close</button>
    </div>
    <div class="wd-table-wrap">
      <table class="wd-table" id="rbi-cf-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Type</th>
            <th class="wd-num">Coupon Rate %</th>
            <th class="wd-num">Amount (₹)</th>
            <th>Received</th>
            <th class="wd-num">TDS (₹)</th>
            <th class="wd-num">Net (₹)</th>
            <th class="wd-actions">Actions</th>
          </tr>
        </thead>
        <tbody id="rbi-cf-body"></tbody>
      </table>
    </div>
  </div>
</section>

<!-- Add / Edit Security Modal -->
<div class="wd-modal" id="rbi-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box wd-modal__box--lg">
    <div class="wd-modal__header">
      <h2 id="rbi-modal-title">Add Security</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="rbi-id">

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Security Type *</label>
          <select class="wd-select" id="rbi-stype">
            <option value="RBI_FRB">RBI Floating Rate Bond</option>
            <option value="GSEC">G-Sec (Fixed Coupon)</option>
            <option value="TBILL">T-Bill (Discount)</option>
            <option value="SDL">SDL (State Dev Loan)</option>
          </select>
        </div>
        <div class="wd-field">
          <label>Security Name *</label>
          <input type="text" class="wd-input" id="rbi-sname" placeholder="e.g. 7.26% GS 2032">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>ISIN</label>
          <input type="text" class="wd-input" id="rbi-isin" placeholder="IN0020220148">
        </div>
        <div class="wd-field">
          <label>Platform</label>
          <input type="text" class="wd-input" id="rbi-platform" placeholder="RBI Retail Direct / Zerodha / NSE goBID">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Face Value (₹) *</label>
          <input type="number" class="wd-input" id="rbi-fv" value="1000" step="1">
        </div>
        <div class="wd-field">
          <label>Quantity *</label>
          <input type="number" class="wd-input" id="rbi-qty" step="1" min="1">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Purchase Price (₹) *</label>
          <input type="number" class="wd-input" id="rbi-pp" step="0.01" min="0">
        </div>
        <div class="wd-field" id="rbi-coupon-wrap">
          <label>Coupon Rate (%) *</label>
          <input type="number" class="wd-input" id="rbi-coupon" step="0.01" min="0">
        </div>
      </div>

      <div class="wd-form-row" id="rbi-freq-wrap">
        <div class="wd-field">
          <label>Coupon Frequency</label>
          <select class="wd-select" id="rbi-cf-freq">
            <option value="SEMI_ANNUAL" selected>Semi-Annual</option>
            <option value="QUARTERLY">Quarterly</option>
            <option value="ANNUAL">Annual</option>
            <option value="FLOATING">Floating (Semi-Annual)</option>
          </select>
        </div>
        <div class="wd-field" id="rbi-frb-spread-wrap" style="display:none">
          <label>Spread over NSS Rate (%)</label>
          <input type="number" class="wd-input" id="rbi-spread" step="0.01" value="0.35">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Purchase Date *</label>
          <input type="date" class="wd-input" id="rbi-pd">
        </div>
        <div class="wd-field">
          <label>Maturity Date *</label>
          <input type="date" class="wd-input" id="rbi-md">
        </div>
      </div>

      <div class="wd-field">
        <label>Notes</label>
        <textarea class="wd-input" id="rbi-notes" rows="2"></textarea>
      </div>

      <div class="wd-info-box">
        Total Invested: <strong id="rbi-preview-invested">—</strong> &nbsp;|&nbsp;
        Annual Interest: <strong id="rbi-preview-interest">—</strong>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="rbi-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="rbi-save">Save &amp; Schedule Cashflows</button>
    </div>
  </div>
</div>

<!-- Mark Cashflow Received Modal -->
<div class="wd-modal" id="rbi-recv-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box wd-modal__box--sm">
    <div class="wd-modal__header">
      <h2>Mark Received</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="rbi-recv-cf-id">
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Received Date</label>
          <input type="date" class="wd-input" id="rbi-recv-date">
        </div>
        <div class="wd-field">
          <label>TDS Deducted (₹)</label>
          <input type="number" class="wd-input" id="rbi-recv-tds" step="0.01" min="0" value="0">
        </div>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="rbi-recv-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="rbi-recv-save">Confirm</button>
    </div>
  </div>
</div>

<!-- FRB Rate Update Modal -->
<div class="wd-modal" id="rbi-frb-rate-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box wd-modal__box--sm">
    <div class="wd-modal__header">
      <h2>Update FRB Coupon Rate</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="rbi-frb-id">
      <p class="wd-text--muted">RBI resets floating rate every 6 months. Enter new rate after reset.</p>
      <div class="wd-form-row">
        <div class="wd-field">
          <label>New Coupon Rate (%) *</label>
          <input type="number" class="wd-input" id="rbi-frb-new-rate" step="0.01" min="0">
        </div>
        <div class="wd-field">
          <label>Effective From *</label>
          <input type="date" class="wd-input" id="rbi-frb-effective">
        </div>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="rbi-frb-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="rbi-frb-save">Update Rate</button>
    </div>
  </div>
</div>
