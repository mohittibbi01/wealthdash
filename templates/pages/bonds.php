<?php defined('WEALTHDASH') or die(); ?>
<section class="wd-page" id="page-bonds">
  <div class="page-header">
    <h1>Corporate Bonds &amp; NCDs</h1>
    <div class="page-actions">
      <button class="wd-btn wd-btn--secondary" id="bonds-upcoming-btn">Upcoming Cashflows</button>
      <button class="wd-btn wd-btn--primary" id="bonds-add-btn">+ Add Bond / NCD</button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div class="wd-cards wd-cards--4">
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Total Invested</span>
      <span class="stat-value" id="bonds-total-invested">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Face Value</span>
      <span class="stat-value" id="bonds-total-fv">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Avg Coupon Rate</span>
      <span class="stat-value" id="bonds-avg-coupon">—</span>
    </div>
    <div class="wd-card wd-card--stat">
      <span class="stat-label">Cashflows (90d)</span>
      <span class="stat-value" id="bonds-upcoming-sum">—</span>
    </div>
  </div>

  <!-- Filters -->
  <div class="wd-toolbar">
    <select class="wd-select" id="bonds-type-filter">
      <option value="">All Types</option>
      <option value="NCD">NCD</option>
      <option value="BOND">Bond</option>
      <option value="DEBENTURE">Debenture</option>
      <option value="COMMERCIAL_PAPER">Commercial Paper</option>
    </select>
    <select class="wd-select" id="bonds-listing-filter">
      <option value="">Listed &amp; Unlisted</option>
      <option value="LISTED">Listed</option>
      <option value="UNLISTED">Unlisted</option>
    </select>
    <input type="text" class="wd-input" id="bonds-search" placeholder="Search issuer…">
  </div>

  <!-- Holdings Table -->
  <div class="wd-table-wrap">
    <table class="wd-table" id="bonds-table">
      <thead>
        <tr>
          <th>Issuer</th>
          <th>Type</th>
          <th>Listing</th>
          <th>Rating</th>
          <th class="wd-num">Face Value</th>
          <th class="wd-num">Qty</th>
          <th class="wd-num">Purchase Price</th>
          <th class="wd-num">Coupon %</th>
          <th class="wd-num">YTM %</th>
          <th>Maturity</th>
          <th>Days Left</th>
          <th class="wd-actions">Actions</th>
        </tr>
      </thead>
      <tbody id="bonds-table-body">
        <tr><td colspan="12" class="wd-empty">Loading…</td></tr>
      </tbody>
    </table>
  </div>

  <!-- Cashflow Timeline Panel -->
  <div class="wd-panel" id="bonds-cashflow-panel" style="display:none">
    <div class="wd-panel__header">
      <h3 id="bonds-cf-title">Cashflow Schedule</h3>
      <button class="wd-btn wd-btn--ghost wd-btn--sm" id="bonds-cf-close">✕ Close</button>
    </div>
    <div class="wd-table-wrap">
      <table class="wd-table" id="bonds-cf-table">
        <thead>
          <tr>
            <th>Date</th><th>Type</th><th class="wd-num">Amount</th>
            <th>Received</th><th class="wd-num">TDS</th><th class="wd-num">Net</th>
            <th class="wd-actions">Actions</th>
          </tr>
        </thead>
        <tbody id="bonds-cf-body"></tbody>
      </table>
    </div>
  </div>
</section>

<!-- Add/Edit Bond Modal -->
<div class="wd-modal" id="bonds-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box wd-modal__box--lg">
    <div class="wd-modal__header">
      <h2 id="bonds-modal-title">Add Bond / NCD</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="bonds-id">

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Issuer Name *</label>
          <input type="text" class="wd-input" id="bonds-issuer" placeholder="Tata Capital Financial Services">
        </div>
        <div class="wd-field">
          <label>ISIN</label>
          <input type="text" class="wd-input" id="bonds-isin" placeholder="INE001A07LG0">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Bond Type *</label>
          <select class="wd-select" id="bonds-type">
            <option value="NCD">NCD</option>
            <option value="BOND">Bond</option>
            <option value="DEBENTURE">Debenture</option>
            <option value="COMMERCIAL_PAPER">Commercial Paper</option>
          </select>
        </div>
        <div class="wd-field">
          <label>Listing *</label>
          <select class="wd-select" id="bonds-listing">
            <option value="LISTED">Listed</option>
            <option value="UNLISTED">Unlisted</option>
          </select>
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Face Value (₹) *</label>
          <input type="number" class="wd-input" id="bonds-fv" value="1000" step="1" min="1">
        </div>
        <div class="wd-field">
          <label>Quantity *</label>
          <input type="number" class="wd-input" id="bonds-qty" step="1" min="1">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Purchase Price (₹) *</label>
          <input type="number" class="wd-input" id="bonds-pp" step="0.01" min="0">
        </div>
        <div class="wd-field">
          <label>Coupon Rate (%) *</label>
          <input type="number" class="wd-input" id="bonds-coupon" step="0.01" min="0">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Coupon Frequency</label>
          <select class="wd-select" id="bonds-coupon-freq">
            <option value="MONTHLY">Monthly</option>
            <option value="QUARTERLY">Quarterly</option>
            <option value="SEMI_ANNUAL" selected>Semi-Annual</option>
            <option value="ANNUAL">Annual</option>
            <option value="CUMULATIVE">Cumulative</option>
            <option value="ON_MATURITY">On Maturity</option>
          </select>
        </div>
        <div class="wd-field">
          <label>Redemption Type</label>
          <select class="wd-select" id="bonds-redemption">
            <option value="BULLET" selected>Bullet</option>
            <option value="CALLABLE">Callable</option>
            <option value="PUTTABLE">Puttable</option>
            <option value="STEP_UP">Step-Up</option>
          </select>
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Purchase Date *</label>
          <input type="date" class="wd-input" id="bonds-pd">
        </div>
        <div class="wd-field">
          <label>Maturity Date *</label>
          <input type="date" class="wd-input" id="bonds-md">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Credit Rating</label>
          <input type="text" class="wd-input" id="bonds-rating" placeholder="AAA / AA+ / etc.">
        </div>
        <div class="wd-field">
          <label>Rating Agency</label>
          <input type="text" class="wd-input" id="bonds-agency" placeholder="CRISIL / ICRA / CARE">
        </div>
      </div>

      <div class="wd-form-row">
        <div class="wd-field">
          <label>Broker / Platform</label>
          <input type="text" class="wd-input" id="bonds-broker" placeholder="Zerodha / Bonds India…">
        </div>
        <div class="wd-field wd-field--checkbox">
          <label><input type="checkbox" id="bonds-secured" checked> Secured Bond</label>
        </div>
      </div>

      <div class="wd-field">
        <label>Notes</label>
        <textarea class="wd-input" id="bonds-notes" rows="2"></textarea>
      </div>

      <div class="wd-info-box">
        Total Face Value: <strong id="bonds-preview-fv">—</strong> &nbsp;|&nbsp;
        Invested: <strong id="bonds-preview-invested">—</strong> &nbsp;|&nbsp;
        Annual Interest: <strong id="bonds-preview-interest">—</strong>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="bonds-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="bonds-save">Save &amp; Generate Cashflows</button>
    </div>
  </div>
</div>

<!-- Mark Received Modal -->
<div class="wd-modal" id="bonds-receive-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box wd-modal__box--sm">
    <div class="wd-modal__header">
      <h2>Mark Cashflow Received</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <input type="hidden" id="bonds-cf-id">
      <div class="wd-form-row">
        <div class="wd-field">
          <label>Received Date</label>
          <input type="date" class="wd-input" id="bonds-cf-recv-date">
        </div>
        <div class="wd-field">
          <label>TDS Deducted (₹)</label>
          <input type="number" class="wd-input" id="bonds-cf-tds" step="0.01" min="0" value="0">
        </div>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="bonds-cf-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="bonds-cf-save">Confirm</button>
    </div>
  </div>
</div>
