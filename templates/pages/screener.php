<?php defined('WEALTHDASH') or die(); ?>
<section class="wd-page" id="page-screener">
  <div class="page-header">
    <h1>Stock Screener</h1>
    <div class="page-actions">
      <button class="wd-btn wd-btn--secondary" id="scr-save-filter-btn">Save Filter</button>
      <button class="wd-btn wd-btn--secondary" id="scr-load-filter-btn">Load Filter</button>
      <button class="wd-btn wd-btn--ghost" id="scr-reset-btn">Reset</button>
      <button class="wd-btn wd-btn--primary" id="scr-run-btn">Run Screen</button>
    </div>
  </div>

  <!-- Filter Panel -->
  <div class="wd-card wd-card--filters" id="scr-filters">
    <div class="scr-filters-grid">

      <!-- Valuation -->
      <fieldset class="scr-filter-group">
        <legend>Valuation</legend>
        <div class="wd-form-row">
          <div class="wd-field">
            <label>P/E Min</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-pe-min" step="0.1" placeholder="0">
          </div>
          <div class="wd-field">
            <label>P/E Max</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-pe-max" step="0.1" placeholder="100">
          </div>
        </div>
        <div class="wd-form-row">
          <div class="wd-field">
            <label>P/B Min</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-pb-min" step="0.1">
          </div>
          <div class="wd-field">
            <label>P/B Max</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-pb-max" step="0.1">
          </div>
        </div>
        <div class="wd-field">
          <label><input type="checkbox" id="scr-excl-null-pe"> Exclude null P/E</label>
        </div>
      </fieldset>

      <!-- Profitability -->
      <fieldset class="scr-filter-group">
        <legend>Profitability</legend>
        <div class="wd-form-row">
          <div class="wd-field">
            <label>ROE Min (%)</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-roe-min" step="0.1">
          </div>
          <div class="wd-field">
            <label>ROCE Min (%)</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-roce-min" step="0.1">
          </div>
        </div>
        <div class="wd-form-row">
          <div class="wd-field">
            <label>Rev Growth 1Y Min (%)</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-rev-min" step="0.1">
          </div>
          <div class="wd-field">
            <label>Profit Growth Min (%)</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-prof-min" step="0.1">
          </div>
        </div>
      </fieldset>

      <!-- Safety -->
      <fieldset class="scr-filter-group">
        <legend>Safety &amp; Liquidity</legend>
        <div class="wd-form-row">
          <div class="wd-field">
            <label>D/E Max</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-de-max" step="0.1">
          </div>
          <div class="wd-field">
            <label>Current Ratio Min</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-cr-min" step="0.1">
          </div>
        </div>
        <div class="wd-form-row">
          <div class="wd-field">
            <label>Div Yield Min (%)</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-dy-min" step="0.01">
          </div>
        </div>
      </fieldset>

      <!-- Classification -->
      <fieldset class="scr-filter-group">
        <legend>Classification</legend>
        <div class="wd-field">
          <label>Market Cap Category</label>
          <select class="wd-select" id="scr-cap-cat" multiple size="4">
            <option value="LARGE">Large Cap</option>
            <option value="MID">Mid Cap</option>
            <option value="SMALL">Small Cap</option>
            <option value="MICRO">Micro Cap</option>
          </select>
        </div>
        <div class="wd-field">
          <label>Exchange</label>
          <select class="wd-select" id="scr-exchange">
            <option value="">Both</option>
            <option value="NSE">NSE</option>
            <option value="BSE">BSE</option>
          </select>
        </div>
      </fieldset>

      <!-- Price -->
      <fieldset class="scr-filter-group">
        <legend>Price</legend>
        <div class="wd-form-row">
          <div class="wd-field">
            <label>Price Min (₹)</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-price-min" step="1">
          </div>
          <div class="wd-field">
            <label>Price Max (₹)</label>
            <input type="number" class="wd-input wd-input--sm" id="scr-price-max" step="1">
          </div>
        </div>
        <div class="wd-field">
          <label>Search</label>
          <input type="text" class="wd-input wd-input--sm" id="scr-q" placeholder="Symbol or Name">
        </div>
      </fieldset>

    </div><!-- /scr-filters-grid -->
  </div>

  <!-- Sort Controls -->
  <div class="wd-toolbar">
    <label class="wd-text--muted">Sort:</label>
    <select class="wd-select" id="scr-sort">
      <option value="market_cap">Market Cap</option>
      <option value="pe_ratio">P/E</option>
      <option value="pb_ratio">P/B</option>
      <option value="roe">ROE</option>
      <option value="roce">ROCE</option>
      <option value="dividend_yield">Div Yield</option>
      <option value="current_price">Price</option>
      <option value="profit_growth_1y">Profit Growth</option>
      <option value="stock_symbol">Symbol</option>
    </select>
    <select class="wd-select" id="scr-dir">
      <option value="DESC">↓ High to Low</option>
      <option value="ASC">↑ Low to High</option>
    </select>
    <span class="wd-text--muted" id="scr-result-count"></span>
  </div>

  <!-- Results Table -->
  <div class="wd-table-wrap">
    <table class="wd-table wd-table--sticky" id="scr-table">
      <thead>
        <tr>
          <th>Symbol</th>
          <th>Name</th>
          <th>Sector</th>
          <th>Cap</th>
          <th class="wd-num">Price (₹)</th>
          <th class="wd-num">P/E</th>
          <th class="wd-num">P/B</th>
          <th class="wd-num">ROE %</th>
          <th class="wd-num">ROCE %</th>
          <th class="wd-num">D/E</th>
          <th class="wd-num">Div Yield %</th>
          <th class="wd-num">Rev Growth %</th>
          <th class="wd-num">Profit Growth %</th>
          <th class="wd-num">52W High</th>
          <th class="wd-num">52W Low</th>
          <th class="wd-actions">Actions</th>
        </tr>
      </thead>
      <tbody id="scr-table-body">
        <tr><td colspan="16" class="wd-empty">Set filters and click Run Screen.</td></tr>
      </tbody>
    </table>
  </div>
  <div class="wd-pagination" id="scr-pagination"></div>
</section>

<!-- Save Filter Modal -->
<div class="wd-modal" id="scr-save-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box wd-modal__box--sm">
    <div class="wd-modal__header">
      <h2>Save Filter</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <div class="wd-field">
        <label>Filter Name *</label>
        <input type="text" class="wd-input" id="scr-filter-name" placeholder="e.g. Value Picks">
      </div>
      <div class="wd-field wd-field--checkbox">
        <label><input type="checkbox" id="scr-filter-default"> Set as default</label>
      </div>
    </div>
    <div class="wd-modal__footer">
      <button class="wd-btn wd-btn--ghost" id="scr-save-cancel">Cancel</button>
      <button class="wd-btn wd-btn--primary" id="scr-save-confirm">Save</button>
    </div>
  </div>
</div>

<!-- Load Filter Modal -->
<div class="wd-modal" id="scr-load-modal" aria-hidden="true">
  <div class="wd-modal__backdrop"></div>
  <div class="wd-modal__box wd-modal__box--sm">
    <div class="wd-modal__header">
      <h2>Saved Filters</h2>
      <button class="wd-modal__close" aria-label="Close">&times;</button>
    </div>
    <div class="wd-modal__body">
      <div id="scr-saved-filters-list" class="wd-list"></div>
    </div>
  </div>
</div>
